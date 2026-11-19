<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\EventListener\StorageKitConflictListener;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function str_starts_with;

/**
 * REQ-RUNTIME-003 race tests (1.7): two kernels, so two real connections, on the same MySQL 8.4 / MariaDB 11 /
 * PostgreSQL database (STORAGE_KIT_TEST_DATABASE_URL; skipped on SQLite, which has no row locks). Kernel A holds a
 * lock or commits in the middle; kernel B runs the bundle's write. Lock waits are capped at one second
 * (innodb_lock_wait_timeout / lock_timeout, and the owner lock's GET_LOCK timeout), so a regression fails fast
 * instead of hanging. Each test asserts the answer, the stored rows and that connection B is still usable.
 */
#[Group('database')]
final class ConcurrencyRaceTest extends KernelTestCase
{
    private StorageKitTestKernel $a;

    private StorageKitTestKernel $b;

    protected function setUp(): void
    {
        if (str_starts_with(StorageKitTestKernel::databaseUrl(), 'sqlite')) {
            self::markTestSkipped('Needs STORAGE_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $config  = ['conflict_response' => ['owner_lock_timeout' => 1]];
        $this->a = $this->bootKernel($config);
        $this->b = $this->bootKernel($config, schema: false);
        foreach ([$this->a, $this->b] as $kernel) {
            $connection = $this->connection($kernel);
            $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->service($kernel, StorageEntityManager::class)->connection();
    }

    private function assertUsable(StorageKitTestKernel $kernel): void
    {
        $connection = $this->connection($kernel);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection($this->a)->fetchOne($sql);
    }

    private function upload(StorageKitTestKernel $kernel): StoredFile
    {
        return $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'acme', Images::png(40, 30), 'photo.png');
    }

    private function follow(KernelBrowser $client): string
    {
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $client->followRedirect();

        return (string) $client->getResponse()->getContent();
    }

    private function expectFailure(callable $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $e) {
            return $e;
        }
        self::fail('The write did not wait for the other connection.');
    }

    public function testTwoFirstSavesOfABucketWaitForEachOther(): void
    {
        $client                          = $this->client($this->b, 'scopeadmin');
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = 'from-b';

        // A saves the first bucket of `acme` under the owner lock; B's save of its own first bucket waits for the lock
        // and answers the retry-safe conflict instead of creating a second active bucket.
        $this->service($this->a, StorageEntityManager::class)->transactional(function () use ($client, $form): void {
            $this->profile($this->a, 'acme', 'from-a');
            $client->submit($form);
            $response = $client->getResponse();
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
            self::assertSame('1', $response->headers->get('Retry-After'));
        }, StorageProfileRepository::ownerLock('acme'));
        $this->assertUsable($this->b);

        // B's retry edits A's bucket: still one active bucket for the owner.
        $client->submit($form);
        self::assertStringContainsString('The storage settings were saved.', $this->follow($client));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM storage_kit_profile WHERE scope = 'acme' AND state = 'active'"));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'from-b'"));
    }

    public function testACancelByAnotherWorkerWinsOverTheRunnerSlice(): void
    {
        $from = $this->profile($this->a, 'acme');
        $to   = $this->profile($this->a, 'acme', 'target', StorageProfile::STATE_TARGET);
        $this->upload($this->a);
        $this->upload($this->a);
        $syncer = $this->service($this->a, StorageSyncer::class);
        $sync   = $syncer->open($from, $to);

        // B cancels (committed on its own connection) while A copies the slice's last file; A's slice end used to
        // write "queued" over the cancel.
        $cancelled = false;
        self::assertTrue($syncer->run($sync, 0, function (StorageSync $running) use (&$cancelled): void {
            if ($cancelled) {
                return;
            }
            $cancelled = true;
            $other     = $this->service($this->b, StorageSyncRepository::class)->find((int) $running->getId());
            self::assertInstanceOf(StorageSync::class, $other);
            self::assertTrue($this->service($this->b, StorageSyncer::class)->cancel($other));
        }));

        self::assertSame(StorageSync::CANCELLED, $sync->getStatus());
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM storage_kit_sync WHERE status = 'cancelled'"));
        $this->assertUsable($this->a);
        $this->assertUsable($this->b);
    }

    public function testALockWaitOnTheSwitchAnswers409AndChangesNothing(): void
    {
        $from = $this->profile($this->a, 'acme');
        $to   = $this->profile($this->a, 'acme', 'target', StorageProfile::STATE_TARGET);
        $file = $this->upload($this->a);
        $sync = $this->service($this->a, StorageSyncer::class)->open($from, $to);
        self::assertTrue($this->service($this->a, StorageSyncer::class)->run($sync));
        self::assertSame(StorageSync::COMPLETED, $sync->getStatus());

        $client = $this->client($this->b, 'scopeadmin');
        $client->request('GET', '/storage/scopes/acme/move');
        $token = $this->token($this->b, $client, 'nowo_storage_kit/move/switch/scope/acme');

        // A holds the file rows; B's switch waits on them, then answers the retry-safe conflict.
        $a = $this->connection($this->a);
        $a->beginTransaction();
        $a->fetchAllAssociative('SELECT id FROM storage_kit_file WHERE profile_id = ? FOR UPDATE', [$from->getId()]);
        $client->request('POST', '/storage/scopes/acme/move/switch', ['csrf_only' => ['_token' => $token]]);
        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        $a->rollBack();

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM storage_kit_profile WHERE id = ' . (int) $from->getId() . " AND state = 'active'"));
        self::assertSame((int) $from->getId(), $this->rows('SELECT profile_id FROM storage_kit_file WHERE id = ' . (int) $file->getId()));
        $this->assertUsable($this->b);

        // The retry switches.
        $client->request('POST', '/storage/scopes/acme/move/switch', ['csrf_only' => ['_token' => $token]]);
        self::assertStringContainsString('Every file now comes from the new bucket.', $this->follow($client));
        self::assertSame((int) $to->getId(), $this->rows('SELECT profile_id FROM storage_kit_file WHERE id = ' . (int) $file->getId()));
    }

    public function testTwoTextsSavingTheSameReferenceStoreItOnce(): void
    {
        $this->profile($this->a, 'acme');
        $file = $this->upload($this->a);
        $text = '![a](storage-file:' . $file->getUuid() . ')';
        $refA = $this->service($this->a, FileReferencesInterface::class);
        $refB = $this->service($this->b, FileReferencesInterface::class);

        // A's save has stored the reference in its open transaction: B waits on the unique key, then gets a retry-safe
        // conflict (never a duplicate, never a unique violation answered as a conflict).
        $a = $this->connection($this->a);
        $a->beginTransaction();
        $refA->sync('note', 'n1', $text, ['board', 'acme']);
        $failure = $this->expectFailure(static fn () => $refB->sync('note', 'n1', $text, ['board', 'acme']));
        self::assertTrue(StorageConflicts::isConflict($failure));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), $failure));
        $this->assertUsable($this->b);

        // The same failure after the request had committed something else answers CONFLICT_002, without Retry-After.
        $request = new Request();
        $ledger  = new CommitLedger();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $request->attributes->set('_controller', FileUploadController::class . '::upload');
        $ledger->record();
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageKitConflictListener())($event);
        $answer = $event->getResponse();
        self::assertNotNull($answer);
        self::assertSame('CONFLICT_002', $answer->headers->get('X-Conflict-Code'));
        self::assertFalse($answer->headers->has('Retry-After'));

        // A commits; B's replay adds nothing: one reference.
        $a->commit();
        $refB->sync('note', 'n1', $text, ['board', 'acme']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM storage_kit_file_reference'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM storage_kit_file WHERE orphaned_since IS NOT NULL'));
    }
}
