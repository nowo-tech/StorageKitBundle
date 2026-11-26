<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Tests\Fixtures\ChatContext;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Tests\Support\StatementHook;
use Nowo\StorageKitBundle\Tests\Support\StatementHooks;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\FileVariantWriterInterface;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function array_keys;
use function dirname;
use function file_put_contents;
use function implode;
use function json_decode;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const PHP_BINARY;

/**
 * 2.2 (REQ-RUNTIME-003): the variant write of a generic file raced by a second database session (two kernels, two
 * real connections) on MySQL 8.4 / MariaDB 11.4 / PostgreSQL: it waits for the file row, never overwrites a removal,
 * answers a retry-safe conflict and leaves no object behind; a real deadlock inside the host's transaction (the
 * lost-savepoint case) closes the connection and keeps it usable. Lock waits are capped at one second.
 */
#[Group('database')]
final class GenericFilesRaceTest extends KernelTestCase
{
    private StorageKitTestKernel $a;

    private StorageKitTestKernel $b;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        if (str_starts_with(StorageKitTestKernel::databaseUrl(), 'sqlite')) {
            self::markTestSkipped('Needs STORAGE_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $extra = static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
        };
        // A config-pinned bucket: both kernels resolve the same profile row.
        $config = [
            'profiles'         => ['main' => ['bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'secret' => 'test-secret-not-real', 'endpoint' => 'http://minio:9000']],
            'instance_profile' => 'main',
        ];
        $this->a = $this->bootKernel($config, extra: $extra);
        $this->b = $this->bootKernel($config, extra: $extra, schema: false);
        foreach ([$this->a, $this->b] as $kernel) {
            $this->connection($kernel)->executeStatement($this->postgres() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function postgres(): bool
    {
        return $this->connection($this->a)->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->service($kernel, StorageEntityManager::class)->connection();
    }

    private function upload(StorageKitTestKernel $kernel, string $bytes = "%PDF-1.7\n%%EOF\n"): StoredFileView
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sk220r');
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser('alice', null), 'chat', 'c1', StoredFileSource::fromPath($path), 'a.pdf', 'application/pdf');
    }

    /**
     * @param int<1, max> $width
     */
    private function put(StorageKitTestKernel $kernel, StoredFileView $view, string $variant = 'thumb', int $width = 20): StoredFileView
    {
        return $this->service($kernel, FileVariantWriterInterface::class)->putVariant($view->uuid, 'chat', 'c1', $variant, Images::png($width, 10), 'image/png');
    }

    private function hooks(StorageKitTestKernel $kernel): StatementHooks
    {
        return $this->service($kernel, StatementHooks::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function variants(string $uuid): array
    {
        $json = $this->connection($this->a)->fetchOne('SELECT variants FROM storage_kit_file WHERE uuid = ?', [$uuid]);

        return (array) json_decode((string) $json, true);
    }

    private function assertUsable(StorageKitTestKernel $kernel): void
    {
        $connection = $this->connection($kernel);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }

    private function assertRetrySafeConflict(Throwable $e): void
    {
        self::assertTrue(StorageConflicts::isConflict($e), $e::class . ': ' . $e->getMessage());
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), $e));
    }

    public function testAVariantWriteWaitsForTheRowLockAndAnswersARetrySafeConflict(): void
    {
        $view = $this->upload($this->a);
        $b    = $this->connection($this->b);
        $b->beginTransaction();
        $b->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ? FOR UPDATE', [$view->uuid]);
        try {
            $this->put($this->a, $view);
            self::fail('the variant was written past the other session\'s row lock');
        } catch (Throwable $e) {
            $this->assertRetrySafeConflict($e);
        } finally {
            $b->rollBack();
        }
        self::assertCount(1, $this->buckets($this->a)->objects('files', 'http://minio:9000'), 'the new object is deleted again');
        self::assertSame([], $this->variants($view->uuid));
        $this->assertUsable($this->a);

        // The retry works.
        self::assertSame(['thumb'], array_keys($this->put($this->a, $view)->variants));
        self::assertCount(2, $this->buckets($this->a)->objects('files', 'http://minio:9000'));
    }

    public function testARemovalCommittedByAnotherSessionBeforeTheLockedReadWins(): void
    {
        $view = $this->upload($this->a);
        $b    = $this->connection($this->b);
        // After A looked the file up and wrote its object, B removes the row and commits; A's locking read sees it.
        $this->hooks($this->a)->onceBefore('SELECT context_type, context_id, size, variants', static function () use ($b, $view): void {
            $b->executeStatement('DELETE FROM storage_kit_file WHERE uuid = ?', [$view->uuid]);
        });
        try {
            $this->put($this->a, $view);
            self::fail('a variant was attached to a removed file');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::CONTEXT, $e->reason);
        }
        self::assertCount(1, $this->buckets($this->a)->objects('files', 'http://minio:9000'), 'only the (orphan) original; the variant object is gone');
        self::assertSame(0, (int) $this->connection($this->a)->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
        $this->assertUsable($this->a);
    }

    public function testTwoWritersOfOneVariantSerializeOnTheRow(): void
    {
        $view   = $this->upload($this->a);
        $caught = null;
        // A holds the row (its locked read ran); B's write of the same variant waits for it and gets a retry-safe conflict.
        $this->hooks($this->a)->onceBefore('UPDATE storage_kit_file SET variants', function () use ($view, &$caught): void {
            try {
                $this->put($this->b, $view, 'thumb', 30);
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        self::assertSame(20, $this->put($this->a, $view)->variants['thumb']['width']);
        self::assertInstanceOf(Throwable::class, $caught);
        $this->assertRetrySafeConflict($caught);
        self::assertSame(20, $this->variants($view->uuid)['thumb']['width'] ?? null);
        $this->assertUsable($this->a);
        $this->assertUsable($this->b);

        // B's retry replaces A's variant (each kernel has its own bucket double: B deletes A's key in its own).
        self::assertSame(30, $this->put($this->b, $view, 'thumb', 30)->variants['thumb']['width']);
        self::assertSame(30, $this->variants($view->uuid)['thumb']['width'] ?? null);
        self::assertCount(1, $this->variants($view->uuid));
    }

    /**
     * The lost-savepoint pattern: a real deadlock inside the host's transaction on MySQL / MariaDB rolls the whole
     * server transaction back, so the unit's savepoint is gone. The unit closes the connection (never treats the
     * rollback as done) and rethrows the deadlock: a retry-safe conflict, the new object deleted, the connection usable.
     */
    public function testARealDeadlockInsideTheHostTransactionClosesTheConnectionAndRethrows(): void
    {
        $connection = $this->connection($this->a);
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only: PostgreSQL rolls back to a savepoint of an aborted transaction.');
        }
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
        $view  = $this->upload($this->a);
        $files = [(int) $connection->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ?', [$view->uuid])];
        for ($i = 0; $i < 10; ++$i) {
            $files[] = (int) $connection->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ?', [$this->upload($this->a)->uuid]);
        }
        $profile = (int) $connection->fetchOne("SELECT id FROM storage_kit_profile WHERE config_name = 'main'");
        self::assertGreaterThan(0, $profile);
        $prefix = (string) $connection->fetchOne('SELECT prefix FROM storage_kit_profile WHERE id = ?', [$profile]);

        // The host's transaction holds the profile row; the peer holds the file rows and then asks for the profile row.
        $connection->beginTransaction();
        $connection->executeStatement("UPDATE storage_kit_profile SET prefix = 'host' WHERE id = ?", [$profile]);
        $peer = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Support/file-deadlock-peer.php', StorageKitTestKernel::databaseUrl(), (string) $profile, implode(',', $files)]);
        $peer->setTimeout(60);
        $peer->start();
        $peer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
        try {
            $this->put($this->a, $view);
            self::fail('no deadlock');
        } catch (Throwable $e) {
            $this->assertRetrySafeConflict($e);
        }
        $peer->wait();
        self::assertSame('done ok', trim(str_replace('ready', '', $peer->getOutput())), 'the peer was not the deadlock victim');
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'the lost savepoint closed the connection');
        $this->assertUsable($this->a);
        self::assertSame($prefix, (string) $connection->fetchOne('SELECT prefix FROM storage_kit_profile WHERE id = ?', [$profile]), 'the host transaction was rolled back by the server');
        self::assertCount(11, $this->buckets($this->a)->objects('files', 'http://minio:9000'), 'no variant object is left');
        self::assertSame(StorageProfile::STATE_ACTIVE, (string) $connection->fetchOne('SELECT state FROM storage_kit_profile WHERE id = ?', [$profile]));
    }
}
