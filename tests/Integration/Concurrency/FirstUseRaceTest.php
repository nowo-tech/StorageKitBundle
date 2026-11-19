<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\StatementHook;
use Nowo\StorageKitBundle\Tests\Support\StatementHooks;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.7.1 (audits UH-SK-2 and UH-SK-7): the first use of the instance's `storage` section creates one row, raced by two
 * kernels (two real connections, two database sessions) on MySQL 8.4 / MariaDB 11 / PostgreSQL. `GET_LOCK()` is
 * re-entrant within one session, so only a second connection proves the owner lock and the re-read under it. Lock
 * waits are capped at one second (innodb_lock_wait_timeout / lock_timeout and `owner_lock_timeout`).
 */
#[Group('database')]
final class FirstUseRaceTest extends KernelTestCase
{
    private StorageKitTestKernel $a;

    private StorageKitTestKernel $b;

    protected function setUp(): void
    {
        if (str_starts_with(StorageKitTestKernel::databaseUrl(), 'sqlite')) {
            self::markTestSkipped('Needs STORAGE_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $config = [
            'conflict_response' => ['owner_lock_timeout' => 1],
            'profiles'          => ['main' => ['bucket' => 'main', 'access_key' => 'k', 'secret' => 'test-secret-not-real', 'endpoint' => 'http://minio:9000']],
        ];
        $hooks = static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
        };
        $this->a = $this->bootKernel($config, extra: $hooks);
        $this->b = $this->bootKernel($config, extra: $hooks, schema: false);
        foreach ([$this->a, $this->b] as $kernel) {
            $connection = $this->connection($kernel);
            $connection->executeStatement($this->postgres() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
        // A provider selected in the section, and no row yet (the settings listener created one: removed again).
        $this->service($this->a, InstanceSettingsInterface::class)->save('storage', ['provider' => 's3_compatible', 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'path_style' => true, 'secret' => 'test-secret-not-real']);
        $this->connection($this->a)->executeStatement('DELETE FROM storage_kit_profile');
        $this->profiles($this->a)->reset();
        $this->entityManager($this->a)->clear();
    }

    private function postgres(): bool
    {
        return $this->connection($this->a)->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->service($kernel, StorageEntityManager::class)->connection();
    }

    private function profiles(StorageKitTestKernel $kernel): StorageProfiles
    {
        return $this->service($kernel, StorageProfiles::class);
    }

    private function instanceRows(): int
    {
        return (int) $this->connection($this->a)->fetchOne("SELECT COUNT(*) FROM storage_kit_profile WHERE scope IS NULL AND config_name IS NULL AND state = 'active'");
    }

    private function assertUsable(StorageKitTestKernel $kernel): void
    {
        $connection = $this->connection($kernel);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }

    private function expectConflict(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            self::assertTrue(StorageConflicts::isConflict($e), $e::class . ': ' . $e->getMessage());
            self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), $e));

            return;
        }
        self::fail('The first use did not wait for the other connection: it created a second active row.');
    }

    public function testAFirstUseInsideAnOpenTransactionIsWaitedForByAnotherConnection(): void
    {
        // A's first use runs inside A's own open transaction (a host write unit calling the uploader): the row is
        // inserted, the owner lock is over (MySQL / MariaDB release GET_LOCK when the unit returns), A has not
        // committed. Before 1.7.1 B missed the uncommitted row on MySQL / MariaDB and inserted a second active row.
        $a = $this->connection($this->a);
        $a->beginTransaction();
        $mine = $this->profiles($this->a)->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $mine);

        // B waits (the locking re-read on MySQL / MariaDB, the transaction-scoped advisory lock on PostgreSQL) and
        // answers the retry-safe conflict.
        $this->expectConflict(fn (): ?StorageProfile => $this->profiles($this->b)->activeOf(null));
        $this->assertUsable($this->b);

        // A commits; B's retry reuses A's row: one active row.
        $a->commit();
        $this->profiles($this->b)->reset();
        $theirs = $this->profiles($this->b)->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $theirs);
        self::assertSame($mine->getId(), $theirs->getId());
        self::assertSame(1, $this->instanceRows());
        $this->assertUsable($this->a);
    }

    public function testTheRowAnotherConnectionCommittedBeforeTheLockIsReadAgainAndReused(): void
    {
        // UH-SK-7: B's first lookup found nothing; A creates and commits the row just before B takes the owner lock
        // (MySQL / MariaDB GET_LOCK, PostgreSQL the bounded advisory lock inside B's unit). B reads again under the
        // lock and reuses A's row.
        $hooks = $this->service($this->b, StatementHooks::class);
        $mine  = null;
        $hooks->onceBefore($this->postgres() ? "SELECT set_config('lock_timeout'" : 'SELECT GET_LOCK', function () use (&$mine): void {
            $mine = $this->profiles($this->a)->activeOf(null);
        });

        $theirs = $this->profiles($this->b)->activeOf(null);

        self::assertNull($hooks->hook, 'A ran before B took the lock');
        self::assertInstanceOf(StorageProfile::class, $mine);
        self::assertInstanceOf(StorageProfile::class, $theirs);
        self::assertSame($mine->getId(), $theirs->getId());
        self::assertSame(1, $this->instanceRows());
        $this->assertUsable($this->a);
        $this->assertUsable($this->b);
    }

    public function testATransactionWhoseSnapshotPredatesTheOtherCommitRetriesInsteadOfDuplicating(): void
    {
        // B's open transaction read the table before A created and committed the row. On MySQL / MariaDB (REPEATABLE
        // READ) B's snapshot cannot see it: the locking read does, so B answers the retry-safe conflict instead of
        // inserting a duplicate. PostgreSQL (READ COMMITTED) sees A's row at once.
        $b = $this->connection($this->b);
        $b->beginTransaction();
        self::assertSame(0, (int) $b->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'));
        $mine = $this->profiles($this->a)->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $mine);

        if ($this->postgres()) {
            self::assertSame($mine->getId(), $this->profiles($this->b)->activeOf(null)?->getId());
            $b->commit();
        } else {
            $this->expectConflict(fn (): ?StorageProfile => $this->profiles($this->b)->activeOf(null));
            self::assertTrue($b->isTransactionActive(), 'your transaction is left to you');
            $b->rollBack();
            $this->profiles($this->b)->reset();
            self::assertSame($mine->getId(), $this->profiles($this->b)->activeOf(null)?->getId());
        }
        self::assertSame(1, $this->instanceRows());
        $this->assertUsable($this->b);
    }

    public function testAConfigProfileRowIsCreatedOnceUnderTheSameRules(): void
    {
        // The row naming config profile `main`, first used inside A's open transaction: B waits, then reuses it.
        $a = $this->connection($this->a);
        $a->beginTransaction();
        $mine = $this->profiles($this->a)->configRow('main');
        self::assertInstanceOf(StorageProfile::class, $mine);
        $this->expectConflict(fn (): ?StorageProfile => $this->profiles($this->b)->configRow('main'));
        $this->assertUsable($this->b);
        $a->commit();

        $this->profiles($this->b)->reset();
        self::assertSame($mine->getId(), $this->profiles($this->b)->configRow('main')?->getId());
        self::assertSame(1, (int) $a->fetchOne("SELECT COUNT(*) FROM storage_kit_profile WHERE config_name = 'main'"));
    }
}
