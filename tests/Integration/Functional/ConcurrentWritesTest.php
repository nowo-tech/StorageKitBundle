<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Doctrine\OwnerLock;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\EarlyCommit;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Tests\Support\StatementHook;
use Nowo\StorageKitBundle\Tests\Support\StatementHooks;
use Nowo\StorageKitBundle\Tests\Unit\Concurrency\StorageConflictsTest;
use Nowo\StorageKitBundle\Ui\ProfileTester;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function file_put_contents;
use function json_decode;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;
use function tempnam;

/**
 * REQ-RUNTIME-003 (1.7) on one connection, any database: the Storage / Move writes read the owner again inside their
 * unit, status changes are compare-and-set, and a lock conflict answers 409 + X-Conflict-Code instead of a 500. The
 * other writer is a statement hook that changes the database just before the bundle's own statement runs (the
 * two-connection versions are in tests/Integration/Concurrency).
 */
final class ConcurrentWritesTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = []): StorageKitTestKernel
    {
        return $this->bootKernel($config, extra: static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
            $services->set(EarlyCommit::class)->args([service('doctrine')])->public()->tag('kernel.event_listener', ['event' => 'kernel.controller']);
        });
    }

    private function hooks(StorageKitTestKernel $kernel): StatementHooks
    {
        return $this->service($kernel, StatementHooks::class);
    }

    private function storage(StorageKitTestKernel $kernel): StorageEntityManager
    {
        return $this->service($kernel, StorageEntityManager::class);
    }

    /**
     * @param list<mixed> $params
     */
    private function sql(StorageKitTestKernel $kernel, string $sql, array $params = []): void
    {
        $this->storage($kernel)->connection()->executeStatement($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function rows(StorageKitTestKernel $kernel, string $sql, array $params = []): int
    {
        return (int) $this->storage($kernel)->connection()->fetchOne($sql, $params);
    }

    private function upload(StorageKitTestKernel $kernel, string $board = 'acme'): StoredFile
    {
        return $this->uploadFile($kernel, new InMemoryUser('alice', null), 'board', $board, Images::png(40, 30), 'photo.png');
    }

    private function follow(KernelBrowser $client): string
    {
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $client->followRedirect();

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @return array{StorageProfile, StorageProfile}
     */
    private function pair(StorageKitTestKernel $kernel): array
    {
        return [$this->profile($kernel, 'acme'), $this->profile($kernel, 'acme', 'target', StorageProfile::STATE_TARGET)];
    }

    private function moveAction(KernelBrowser $client, StorageKitTestKernel $kernel, string $action): string
    {
        $client->request('GET', '/storage/scopes/acme/move');
        $client->request('POST', '/storage/scopes/acme/move/' . $action, ['csrf_only' => ['_token' => $this->token($kernel, $client, 'nowo_storage_kit/move/' . $action . '/scope/acme')]]);

        return $this->follow($client);
    }

    public function testAFirstSaveRacingAnotherKeepsOneBucket(): void
    {
        $kernel                          = $this->kernel();
        $client                          = $this->client($kernel, 'scopeadmin');
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = 'mine';

        // Another admin's first save commits between this page's read and its write unit.
        $this->hooks($kernel)->onceBefore('SELECT scope FROM storage_kit_profile WHERE state', function () use ($kernel): void {
            $theirs = new StorageProfile('acme');
            $theirs->configure('s3_compatible', true, 'http://minio:9000', null, 'us-east-1', 'theirs', '', 'test-key', 'test-secret-not-real', true);
            $this->storage($kernel)->insert($theirs);
        });
        $client->submit($form);

        self::assertStringContainsString('Someone else changed this bucket meanwhile', $this->follow($client));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE scope = 'acme' AND state = 'active'"));
        self::assertSame(0, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'mine'"));
        self::assertNotContains(StorageAuditEvent::PROFILE_SAVED, $this->audit($kernel)->actions());
    }

    public function testADeadlockOnTheSettingsPageAnswers409WithTheCode(): void
    {
        $kernel                          = $this->kernel();
        $client                          = $this->client($kernel, 'scopeadmin');
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = 'mine';

        $this->hooks($kernel)->onceBefore('SELECT scope FROM storage_kit_profile WHERE state', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        $client->submit($form);

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertTrue($this->entityManager($kernel)->isOpen(), 'the host manager is neither closed nor reset');
        self::assertSame(0, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'mine'"));

        // The retry succeeds on the same connection.
        $client->submit($form);
        self::assertStringContainsString('The storage settings were saved.', $this->follow($client));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'mine'"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commitTracking(): iterable
    {
        yield 'bundle (default)' => ['bundle', 'CONFLICT_002'];
        yield 'off' => ['off', 'CONFLICT_001'];
    }

    #[DataProvider('commitTracking')]
    public function testAConflictAfterAnEarlierCommitOfTheRequestAnswers002WithoutTransactionDoctrinesBundle(string $tracking, string $code): void
    {
        // 1.7.1 (UH-SK-4): transaction-doctrine's bundle is not registered here; the bundle's own commit tracking on
        // its connection sees the host listener's commit made earlier in the same request (`off`: not seen).
        $kernel                          = $this->kernel(['conflict_response' => ['commit_tracking' => $tracking]]);
        $client                          = $this->client($kernel, 'scopeadmin');
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = 'mine';

        $this->service($kernel, EarlyCommit::class)->armed = true;
        $this->hooks($kernel)->onceBefore('SELECT scope FROM storage_kit_profile WHERE state', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        $client->submit($form);

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($code, $response->headers->get('X-Conflict-Code'));
        self::assertSame($code === 'CONFLICT_001', $response->headers->has('Retry-After'));
        self::assertFalse($this->service($kernel, EarlyCommit::class)->armed, 'the earlier commit ran');
        self::assertSame(0, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'mine'"));
    }

    public function testATargetSavedMeanwhileIsNotOverwritten(): void
    {
        $kernel = $this->kernel();
        $this->profile($kernel, 'acme');
        $client = $this->client($kernel, 'scopeadmin');
        $form   = $client->request('GET', '/storage/scopes/acme/move')->selectButton('Save the new bucket')->form([
            'storage_target[endpoint]'   => 'http://minio:9000',
            'storage_target[region]'     => 'us-east-1',
            'storage_target[bucket]'     => 'mine',
            'storage_target[prefix]'     => '',
            'storage_target[access_key]' => 'test-key',
            'storage_target[secret]'     => 'test-secret-not-real',
        ]);
        $this->hooks($kernel)->onceBefore('SELECT scope FROM storage_kit_profile WHERE state', function () use ($kernel): void {
            $theirs = new StorageProfile('acme', StorageProfile::STATE_TARGET);
            $theirs->configure('s3_compatible', false, 'http://minio:9000', null, 'us-east-1', 'theirs', '', 'test-key', 'test-secret-not-real', true);
            $this->storage($kernel)->insert($theirs);
        });
        $client->submit($form);

        self::assertStringContainsString('This action is not available now.', $this->follow($client));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE scope = 'acme' AND state = 'target'"));
        self::assertSame(0, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE bucket = 'mine'"));
    }

    public function testStartingASyncReReadsTheOwnerAndTheSync(): void
    {
        $kernel            = $this->kernel();
        [$active, $target] = $this->pair($kernel);
        $client            = $this->client($kernel, 'scopeadmin');

        // The target was switched to or discarded meanwhile: nothing is queued.
        $this->hooks($kernel)->onceBefore('SELECT state FROM storage_kit_profile WHERE id', function () use ($kernel, $target): void {
            $this->sql($kernel, "UPDATE storage_kit_profile SET state = 'retired' WHERE id = ?", [$target->getId()]);
        });
        self::assertStringContainsString('This action is not available now.', $this->moveAction($client, $kernel, 'sync'));
        self::assertSame(0, $this->rows($kernel, 'SELECT COUNT(*) FROM storage_kit_sync'));
        $this->sql($kernel, "UPDATE storage_kit_profile SET state = 'target' WHERE id = ?", [$target->getId()]);

        // Another request queued the sync meanwhile: it is not queued (nor dispatched) twice.
        $profiles = $this->service($kernel, StorageProfileRepository::class);
        $active   = $profiles->activeOf('acme');
        $target   = $profiles->targetOf('acme');
        self::assertInstanceOf(StorageProfile::class, $active);
        self::assertInstanceOf(StorageProfile::class, $target);
        $move = $this->service($kernel, StorageMove::class);
        $sync = $this->service($kernel, StorageSyncer::class)->open($active, $target);
        self::assertTrue($this->service($kernel, StorageSyncer::class)->cancel($sync));
        $this->hooks($kernel)->onceBefore('SELECT state FROM storage_kit_profile WHERE id', function () use ($kernel, $sync): void {
            $this->sql($kernel, "UPDATE storage_kit_sync SET status = 'queued' WHERE id = ?", [$sync->getId()]);
        });
        self::assertNull($move->start($active, $target, null));
        self::assertSame(StorageSync::QUEUED, $sync->getStatus(), 'the sync was read again inside the unit');
    }

    public function testACancelLosesToARunnerThatFinishedMeanwhile(): void
    {
        $kernel            = $this->kernel();
        [$active, $target] = $this->pair($kernel);
        $sync              = $this->service($kernel, StorageSyncer::class)->open($active, $target);
        $client            = $this->client($kernel, 'scopeadmin');

        $this->hooks($kernel)->onceBefore('UPDATE storage_kit_sync SET', function () use ($kernel, $sync): void {
            $this->sql($kernel, "UPDATE storage_kit_sync SET status = 'completed' WHERE id = ?", [$sync->getId()]);
        });
        self::assertStringContainsString('This action is not available now.', $this->moveAction($client, $kernel, 'cancel'));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_sync WHERE status = 'completed'"));
        self::assertNotContains(StorageAuditEvent::MOVE_SYNC_CANCELLED, $this->audit($kernel)->actions());

        // A sync that is no longer open is not cancelled at all.
        $completed = $this->service($kernel, StorageSyncRepository::class)->find((int) $sync->getId());
        self::assertInstanceOf(StorageSync::class, $completed);
        self::assertFalse($this->service($kernel, StorageSyncer::class)->cancel($completed));
    }

    public function testARunnerSliceDoesNotUndoACancel(): void
    {
        $kernel            = $this->kernel();
        [$active, $target] = $this->pair($kernel);
        $this->upload($kernel);
        $this->upload($kernel);
        $syncer = $this->service($kernel, StorageSyncer::class);
        $sync   = $syncer->open($active, $target);

        // The slice ends after its first batch (0 seconds); a user cancels while its last file is copied. Before 1.7
        // the runner's "queued for the next slice" overwrote the cancel.
        $cancelled = false;
        self::assertTrue($syncer->run($sync, 0, function (StorageSync $running) use ($kernel, &$cancelled): void {
            if (!$cancelled) {
                $cancelled = true;
                $this->sql($kernel, "UPDATE storage_kit_sync SET status = 'cancelled' WHERE id = ?", [$running->getId()]);
            }
        }));
        self::assertSame(StorageSync::CANCELLED, $sync->getStatus());
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_sync WHERE status = 'cancelled'"));
    }

    public function testDiscardKeepsATargetASyncStartedMeanwhile(): void
    {
        $kernel            = $this->kernel();
        [$active, $target] = $this->pair($kernel);
        $sync              = $this->service($kernel, StorageSyncer::class)->open($active, $target);
        $this->service($kernel, StorageSyncer::class)->cancel($sync);
        $client = $this->client($kernel, 'scopeadmin');

        $this->hooks($kernel)->onceBefore('SELECT COUNT(*) FROM storage_kit_sync', function () use ($kernel, $sync): void {
            $this->sql($kernel, "UPDATE storage_kit_sync SET status = 'running' WHERE id = ?", [$sync->getId()]);
        });
        self::assertStringContainsString('This action is not available now.', $this->moveAction($client, $kernel, 'discard'));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE state = 'target'"));
    }

    public function testCompareAndSetGuards(): void
    {
        $kernel            = $this->kernel();
        [$active, $target] = $this->pair($kernel);
        $storage           = $this->storage($kernel);
        $sync              = $this->service($kernel, StorageSyncer::class)->open($active, $target);

        // A detached entity whose guard no longer matches: nothing written, nothing refreshed.
        $this->bundleManager($kernel)->detach($sync);
        $sync->complete();
        self::assertFalse($storage->updateIf($sync, StorageSyncer::STATUS_FIELDS, 'status', [StorageSync::RUNNING]));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_sync WHERE status = 'queued'"));
        self::assertTrue($storage->updateIf($sync, StorageSyncer::STATUS_FIELDS, 'status', [StorageSync::QUEUED]));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_sync WHERE status = 'completed'"));

        foreach ([[[1, 2], 'status'], [[1], 'nope']] as [$ids, $field]) {
            try {
                $storage->updateRows(StorageSync::class, $ids, ['status' => 'queued'], [$field, ['running']]);
                self::fail('expected a LogicException');
            } catch (LogicException $e) {
                self::assertStringContainsString('guard a write', $e->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        $storage->updateIf(new StorageSync($active, $target), StorageSyncer::STATUS_FIELDS, 'status', [StorageSync::QUEUED]);
    }

    public function testAnUploadDeadlockAnswersConflict001AndKeepsNoObject(): void
    {
        $kernel = $this->kernel();
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/00000000-0000-4000-8000-000000000000/preview');
        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, Images::png(40, 30));

        $this->hooks($kernel)->onceBefore('INSERT INTO storage_kit_file', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        $client->request('POST', '/storage/upload/board/b1', [], ['file' => new UploadedFile($path, 'a.png', 'image/png', null, true)], [
            'HTTP_X_CSRF_TOKEN' => $this->token($kernel, $client, UploadEndpoint::tokenId('board', 'b1')),
        ]);

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(['error' => 'conflict', 'message' => 'The same data was being changed at the same time. Try again.', 'code' => 'CONFLICT_001'], json_decode((string) $response->getContent(), true));
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'), 'the objects of the failed insert are deleted again');
        self::assertSame(0, $this->rows($kernel, 'SELECT COUNT(*) FROM storage_kit_file'));
    }

    public function testTheFirstUseOfTheInstanceSectionCreatesOneRow(): void
    {
        $kernel = $this->kernel();

        // Another worker creates the section row between this one's lookup and its lock: it is read again under the
        // lock and reused (before 1.7 a second active row was created). The row is created on first use, here by the
        // settings listener when a provider is first selected.
        $this->hooks($kernel)->onceBeforeNth('FROM storage_kit_profile s0_', 2, function () use ($kernel): void {
            $theirs = new StorageProfile();
            $theirs->useInstanceSettings();
            $this->storage($kernel)->insert($theirs);
        });
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => 's3_compatible', 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'path_style' => true, 'secret' => 'test-secret-not-real']);
        self::assertNull($this->hooks($kernel)->hook, 'the other worker ran');
        $file = $this->uploadFile($kernel, new InMemoryUser('alice', null), 'board', 'b1', Images::png(40, 30), 'photo.png');

        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE state = 'active' AND scope IS NULL"));
        self::assertSame($this->rows($kernel, 'SELECT MIN(id) FROM storage_kit_profile'), (int) $file->getProfile()->getId());
    }

    public function testARowTheLockingReadSeesButTheManagerDoesNotIsARetrySafeConflict(): void
    {
        $kernel = $this->kernel();
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => 's3_compatible', 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'path_style' => true, 'secret' => 'test-secret-not-real']);
        $this->sql($kernel, 'DELETE FROM storage_kit_profile');
        $profiles = $this->service($kernel, StorageProfiles::class);
        $profiles->reset();

        // 1.7.1 (UH-SK-2): the locking re-read finds a row the manager's read then misses (on MySQL / MariaDB: your
        // transaction's snapshot predates the other commit). Creating one would duplicate it: retry-safe conflict.
        $hooks = $this->hooks($kernel);
        $hooks->onceBefore('SELECT id FROM storage_kit_profile WHERE scope IS NULL', function () use ($kernel, $hooks): void {
            $theirs = new StorageProfile();
            $theirs->useInstanceSettings();
            $this->storage($kernel)->insert($theirs);
            $hooks->onceBeforeNth('FROM storage_kit_profile s0_', 1, function () use ($kernel): void {
                $this->sql($kernel, 'DELETE FROM storage_kit_profile');
            });
        });
        try {
            $profiles->activeOf(null);
            self::fail('expected a conflict');
        } catch (TransientStorageFailure $e) {
            self::assertSame(OwnerLock::MESSAGE, $e->getMessage());
            self::assertTrue(StorageConflicts::isConflict($e));
        }
        self::assertNull($hooks->hook);

        // The retry creates the one row.
        $profiles->reset();
        self::assertInstanceOf(StorageProfile::class, $profiles->activeOf(null));
        self::assertSame(1, $this->rows($kernel, "SELECT COUNT(*) FROM storage_kit_profile WHERE state = 'active' AND scope IS NULL"));
    }

    /**
     * 2.0 (private manager only): a failed bundle flush answers 409 and never touches the application's manager, open
     * or closed (the private manager's own failure is covered by PrivateEntityManagerKernelTest).
     */
    public function testAFailedFlushNeverResetsTheApplicationsManager(): void
    {
        $kernel                          = $this->kernel();
        $client                          = $this->client($kernel, 'scopeadmin');
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = 'mine';
        $before                          = $this->entityManager($kernel);

        // A deadlock inside the flush: 409, and the open manager is kept as it is.
        $this->hooks($kernel)->onceBefore('INSERT INTO storage_kit_profile', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        $client->submit($form);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame($before, $this->entityManager($kernel));
        self::assertTrue($before->isOpen());

        // Even a closed application manager is never reset by the bundle (only the private manager is replaced).
        $before->close();
        $this->storage($kernel)->resetIfClosed();
        self::assertSame($before, $this->entityManager($kernel));
        self::assertFalse($before->isOpen());
    }

    public function testAProfileTestOfAnUnsavedBucketWritesNothing(): void
    {
        $kernel  = $this->kernel();
        $profile = new StorageProfile('acme');
        $profile->configure('s3_compatible', true, 'http://minio:9000', null, 'us-east-1', 'files', 'app', 'test-key', 'test-secret-not-real', true);

        $this->service($kernel, ProfileTester::class)->test($profile, null);

        self::assertNotNull($profile->getTestedAt());
        self::assertSame(0, $this->rows($kernel, 'SELECT COUNT(*) FROM storage_kit_profile'));
    }
}
