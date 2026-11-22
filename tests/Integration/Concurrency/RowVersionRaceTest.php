<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\StorageKitBundle\Concurrency\RowVersion;
use Nowo\StorageKitBundle\Concurrency\StaleEditException;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\StatementHook;
use Nowo\StorageKitBundle\Tests\Support\StatementHooks;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Process\Process;

use function dirname;
use function implode;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function trim;

use const PHP_BINARY;

/**
 * 2.1 (REQ-RUNTIME-003 rule 4, audit ZH-M1): the bucket form's row version against real servers, with two connections
 * (two kernels, or a second process for a real deadlock) on MySQL 8.4, MariaDB 11 and PostgreSQL
 * (STORAGE_KIT_TEST_DATABASE_URL; skipped on SQLite, which has no row locks). Each test asserts the answer, the stored
 * row and that the connection is still usable (nesting level 0, the next save works).
 */
#[Group('database')]
final class RowVersionRaceTest extends KernelTestCase
{
    private StorageKitTestKernel $a;

    private StorageKitTestKernel $b;

    protected function setUp(): void
    {
        if (str_starts_with(StorageKitTestKernel::databaseUrl(), 'sqlite')) {
            self::markTestSkipped('Needs STORAGE_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $config = ['conflict_response' => ['owner_lock_timeout' => 1]];
        $hooks  = static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
        };
        $this->a = $this->bootKernel($config, extra: $hooks);
        $this->b = $this->bootKernel($config, extra: $hooks, schema: false);
        foreach ([$this->a, $this->b] as $kernel) {
            $connection = $this->connection($kernel);
            $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->service($kernel, StorageEntityManager::class)->connection();
    }

    private function version(StorageProfile $profile): int
    {
        return RowVersion::read($this->connection($this->a), 'storage_kit_profile', (int) $profile->getId());
    }

    private function bucket(StorageProfile $profile): string
    {
        return (string) $this->connection($this->a)->fetchOne('SELECT bucket FROM storage_kit_profile WHERE id = ?', [$profile->getId()]);
    }

    private function form(KernelBrowser $client, string $bucket): Form
    {
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = $bucket;
        // Each test kernel has its own encryption key: the stored secret is typed again so the bucket stays complete.
        $form['storage_profile[secret]'] = 'test-secret-not-real';

        return $form;
    }

    private function assertUsable(StorageKitTestKernel $kernel): void
    {
        $connection = $this->connection($kernel);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }

    public function testTwoEditorsWithTheSameVersionOneWinsTheOtherIsStale(): void
    {
        $profile = $this->profile($this->a, 'acme');
        $clientA = $this->client($this->a, 'scopeadmin');
        $clientB = $this->client($this->b, 'scopeadmin');
        $formA   = $this->form($clientA, 'from-a');
        $formB   = $this->form($clientB, 'from-b');

        // A's save commits first on its own connection.
        $clientA->submit($formA);
        self::assertSame(302, $clientA->getResponse()->getStatusCode());

        // B's page (same version) is stale: nothing written over A's save.
        $clientB->submit($formB);
        self::assertSame(409, $clientB->getResponse()->getStatusCode());
        self::assertSame(StaleEditException::ERROR_CODE, $clientB->getResponse()->headers->get('X-Error-Code'));
        self::assertFalse($clientB->getResponse()->headers->has('X-Conflict-Code'));
        self::assertSame('from-a', $this->bucket($profile));
        self::assertSame(2, $this->version($profile));
        $this->assertUsable($this->a);
        $this->assertUsable($this->b);
    }

    public function testASaveWaitingForAnOpenSaveIsStaleOnceTheOtherCommits(): void
    {
        $profile = $this->profile($this->a, 'acme');
        $clientB = $this->client($this->b, 'scopeadmin');
        $formB   = $this->form($clientB, 'from-b');

        // A has claimed the row (same version as B's page) and not committed yet: B's save waits for the row lock and
        // answers the retry-safe lock conflict, writing nothing.
        $a = $this->connection($this->a);
        $a->beginTransaction();
        self::assertTrue(RowVersion::advance($a, 'storage_kit_profile', (int) $profile->getId(), 1));
        $a->executeStatement("UPDATE storage_kit_profile SET bucket = 'from-a' WHERE id = ?", [$profile->getId()]);
        $clientB->submit($formB);
        self::assertSame(409, $clientB->getResponse()->getStatusCode());
        self::assertSame('CONFLICT_001', $clientB->getResponse()->headers->get('X-Conflict-Code'));
        $this->assertUsable($this->b);
        $a->commit();

        // B's retry of the same page is now stale (A's save won): never a lost update.
        $clientB->submit($formB);
        self::assertSame(409, $clientB->getResponse()->getStatusCode());
        self::assertSame(StaleEditException::ERROR_CODE, $clientB->getResponse()->headers->get('X-Error-Code'));
        self::assertSame('from-a', $this->bucket($profile));
        self::assertSame(2, $this->version($profile));
        $this->assertUsable($this->b);
    }

    /**
     * ZH-M1: a real deadlock inside the save's flush on MySQL / MariaDB rolls the whole server transaction back, so the
     * ORM's savepoint is gone and its rollback fails (1305). The runner repairs the nesting level: the page answers
     * 409 CONFLICT_001 (never a 500), the connection is back at level 0 and the next save works.
     */
    public function testARealDeadlockInTheFlushAnswers409AndLeavesTheConnectionUsable(): void
    {
        $connection = $this->connection($this->b);
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only: PostgreSQL rolls back to a savepoint of an aborted transaction.');
        }
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
        $profile = $this->profile($this->a, 'acme');
        $held    = $this->profile($this->a, 'other', 'held');
        $extra   = [];
        for ($i = 0; $i < 10; ++$i) {
            $extra[] = (int) $this->profile($this->a, 'other', 'extra', StorageProfile::STATE_RETIRED)->getId();
        }
        $client = $this->client($this->b, 'scopeadmin');
        $form   = $this->form($client, 'mine');

        $peer = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Support/deadlock-peer.php', StorageKitTestKernel::databaseUrl(), (string) $profile->getId(), (string) $held->getId(), implode(',', $extra)]);
        $peer->setTimeout(60);
        $peer->start();
        $peer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));

        // B's save holds the profile row (version claim) and, in the flush, waits for the row the peer holds; the peer
        // then asks for the profile row: a deadlock, and B (fewer changed rows) is the victim.
        $hooks       = $this->service($this->b, StatementHooks::class);
        $hooks->hook = static function (string $sql) use ($hooks, $connection, $held): void {
            // The flush's UPDATE of the profile (not the version claim before it).
            if (str_starts_with($sql, 'UPDATE storage_kit_profile SET') && !str_contains($sql, 'row_version')) {
                $hooks->hook = null;
                $connection->fetchOne('SELECT id FROM storage_kit_profile WHERE id = ? FOR UPDATE', [$held->getId()]);
            }
        };
        $client->submit($form);
        $peer->wait();
        $peerResult = trim(str_replace('ready', '', $peer->getOutput()));

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode(), $peerResult);
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('done ok', $peerResult, 'the peer was not the deadlock victim');
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame('files', $this->bucket($profile));
        self::assertSame(1, $this->version($profile));

        // Nothing was written, so the same page saves on the same (repaired) connection.
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('mine', $this->bucket($profile));
        self::assertSame(2, $this->version($profile));
        $this->assertUsable($this->b);
    }
}
