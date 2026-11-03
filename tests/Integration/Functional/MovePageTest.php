<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Command\ForgetRetiredProfilesCommand;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The Move page: the target bucket, the sync on the Messenger worker (or the CLI), *Switch* only after a complete,
 * verified sync, *Cancel*, *Discard*; every action CSRF-bound to the action and the owner, audited, path-only
 * redirects.
 */
final class MovePageTest extends KernelTestCase
{
    /** @var list<RunStorageSyncMessage> */
    private array $messages = [];

    private function upload(StorageKitTestKernel $kernel, string $board = 'b1'): StoredFile
    {
        return $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', $board, Images::png(2000, 1000), 'photo.png');
    }

    private function saveTarget(KernelBrowser $client, string $url = '/admin/storage/move', string $bucket = 'new-files', string $secret = 'test-secret-not-real'): void
    {
        $crawler = $client->request('GET', $url);
        $form    = $crawler->selectButton('Save the new bucket')->form([
            'storage_target[endpoint]'   => 'http://minio:9000',
            'storage_target[region]'     => 'us-east-1',
            'storage_target[bucket]'     => $bucket,
            'storage_target[prefix]'     => '',
            'storage_target[access_key]' => 'test-key',
            'storage_target[secret]'     => $secret,
        ]);
        $client->submit($form);
    }

    private function act(KernelBrowser $client, string $action, string $url = '/admin/storage/move', ?StorageKitTestKernel $kernel = null, ?string $owner = null): string
    {
        $crawler = $client->request('GET', $url);
        $form    = $crawler->filter('[data-testid="storage-kit-move-' . $action . '"]')->closest('form');
        if (!$form instanceof Crawler) {
            self::assertNotNull($kernel, 'the ' . $action . ' action is disabled');
            $client->request('POST', $url . '/' . $action, ['csrf_only' => ['_token' => $this->token($kernel, $client, 'nowo_storage_kit/move/' . $action . '/' . ($owner ?? 'instance'))]]);
        } else {
            $client->submit($form->form());
        }
        if ($kernel instanceof StorageKitTestKernel) {
            $this->collect($kernel);
        }
        self::assertSame($url, $client->getResponse()->headers->get('Location'));
        $client->followRedirect();

        return (string) $client->getResponse()->getContent();
    }

    private function runWorker(StorageKitTestKernel $kernel): void
    {
        $handler = $this->service($kernel, RunStorageSyncHandler::class);
        for ($i = 0; $i < 10 && $this->messages !== []; ++$i) {
            $messages       = $this->messages;
            $this->messages = [];
            foreach ($messages as $message) {
                $handler($message);
                $this->collect($kernel);
            }
        }
    }

    /**
     * Messages sent since the last call (the in-memory transport is reset between requests).
     */
    private function collect(StorageKitTestKernel $kernel): void
    {
        $transport = $this->container($kernel)->has('test.messenger.transport') ? $this->service($kernel, 'test.messenger.transport') : null;
        if (!$transport instanceof InMemoryTransport) {
            return;
        }
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RunStorageSyncMessage::class, $message);
            $this->messages[] = $message;
        }
        $transport->reset();
    }

    public function testAFullMove(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->alias(RunStorageSyncHandler::class . '.public', RunStorageSyncHandler::class)->public();
        });
        $active = $this->profile($kernel);
        $file   = $this->upload($kernel);
        $client = $this->client($kernel);

        $client->request('GET', '/admin/storage/move');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Not started.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString($active->getUuid(), (string) $client->getResponse()->getContent());

        // An incomplete target is refused.
        $this->saveTarget($client, secret: '');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Set the bucket, the access key, the secret key and (S3-compatible service) the endpoint first.', (string) $client->getResponse()->getContent());

        $this->saveTarget($client);
        self::assertSame('/admin/storage/move', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('The new bucket was saved.', (string) $client->getResponse()->getContent());
        $target = $this->service($kernel, StorageProfileRepository::class)->targetOf(null);
        self::assertInstanceOf(StorageProfile::class, $target);
        self::assertFalse($target->isEnabled());

        self::assertStringContainsString('The connection works', $this->act($client, 'test'));
        self::assertStringContainsString('Copy every file before switching.', $this->act($client, 'switch', kernel: $kernel));
        self::assertStringContainsString('Copying started in the background.', $this->act($client, 'sync', kernel: $kernel));
        self::assertStringContainsString('Copying', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('storage-kit-progress', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('This action is not available now.', $this->act($client, 'discard', kernel: $kernel));

        $this->runWorker($kernel);
        $html = $client->request('GET', '/admin/storage/move')->html();
        self::assertStringContainsString('Completed and verified', $html);
        self::assertStringContainsString('3 copied, 0 already there', $html);
        self::assertCount(3, $this->buckets($kernel)->objects('new-files', 'http://minio:9000'));

        // A file uploaded after the sync blocks the switch until it is copied too.
        $this->upload($kernel);
        self::assertStringContainsString('Files were uploaded since the copy', $this->act($client, 'switch', kernel: $kernel));
        $this->act($client, 'sync', kernel: $kernel);
        $this->runWorker($kernel);
        self::assertStringContainsString('Every file now comes from the new bucket.', $this->act($client, 'switch', kernel: $kernel));

        $this->entityManager($kernel)->clear();
        $repository = $this->service($kernel, StorageProfileRepository::class);
        self::assertSame($target->getUuid(), $repository->activeOf(null)?->getUuid());
        self::assertSame(StorageProfile::STATE_RETIRED, $repository->findOneByUuid($active->getUuid())?->getState());
        self::assertSame($target->getUuid(), $this->entityManager($kernel)->find(StoredFile::class, $file->getId())?->getProfile()->getUuid());
        $html = $client->request('GET', '/admin/storage/move')->html();
        self::assertStringContainsString('Previous buckets', $html);
        self::assertStringContainsString($active->getUuid(), $html);

        self::assertSame([
            StorageAuditEvent::FILE_UPLOADED,
            StorageAuditEvent::MOVE_TARGET_SAVED,
            StorageAuditEvent::PROFILE_TESTED,
            StorageAuditEvent::MOVE_SYNC_STARTED,
            StorageAuditEvent::FILE_UPLOADED,
            StorageAuditEvent::MOVE_SYNC_STARTED,
            StorageAuditEvent::MOVE_SWITCHED,
        ], $this->audit($kernel)->actions());
    }

    public function testMovingTheInstanceSettingsBucketSwapsTheSection(): void
    {
        $kernel   = $this->bootKernel();
        $settings = $this->service($kernel, InstanceSettingsInterface::class);
        $settings->save('storage', ['provider' => StorageProvider::S3_COMPATIBLE, 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'path_style' => true, 'secret' => 'old-secret-not-real']);
        $file   = $this->upload($kernel);
        $active = $file->getProfile();
        self::assertTrue($active->isSettingsBacked());
        $client = $this->client($kernel);

        $this->saveTarget($client);
        $this->act($client, 'sync', kernel: $kernel);
        $this->runWorker($kernel);
        self::assertStringContainsString('Every file now comes from the new bucket.', $this->act($client, 'switch', kernel: $kernel));

        $this->entityManager($kernel)->clear();
        $repository = $this->service($kernel, StorageProfileRepository::class);
        $target     = $repository->activeOf(null);
        self::assertNotNull($target);
        self::assertTrue($target->isSettingsBacked(), 'the new active row reads the section');
        self::assertNull($target->getSecret());
        $instance = $this->service($kernel, InstanceStorageSettings::class);
        self::assertSame(['new-files', 'test-secret-not-real'], [$instance->settings()?->bucket, $instance->secret()]);

        // The retired row keeps a copy of the old connection, so its bucket stays readable.
        $retired = $repository->findOneByUuid($active->getUuid());
        self::assertNotNull($retired);
        self::assertSame([StorageProfile::STATE_RETIRED, StorageProfile::SOURCE_DATABASE, 'files', 'old-secret-not-real'], [$retired->getState(), $retired->getSource(), $retired->getBucket(), $retired->getSecret()]);
        self::assertSame('files', $this->service($kernel, StorageProfiles::class)->settingsOf($retired)?->bucket);
        self::assertSame($target->getUuid(), $this->entityManager($kernel)->find(StoredFile::class, $file->getId())?->getProfile()->getUuid());

        $client->request('GET', '/admin/settings/sections/storage');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('new-files', $html);
        self::assertStringNotContainsString('secret-not-real', $html);
    }

    public function testASwitchTheSectionRefusesChangesNothing(): void
    {
        $kernel   = $this->bootKernel();
        $settings = $this->service($kernel, InstanceSettingsInterface::class);
        $settings->save('storage', ['provider' => StorageProvider::S3_COMPATIBLE, 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'prefix' => 'app', 'access_key' => 'test-key', 'path_style' => true, 'secret' => 'old-secret-not-real']);
        $file   = $this->upload($kernel);
        $client = $this->client($kernel);
        $this->saveTarget($client);
        $this->act($client, 'sync', kernel: $kernel);
        $this->runWorker($kernel);
        // The allow-list changed since the target was saved (simulated): the section refuses its endpoint now.
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET endpoint = 'http://10.0.0.5:9000' WHERE state = 'target'");
        $this->entityManager($kernel)->clear();

        self::assertStringContainsString('The Storage settings refused the new bucket', $this->act($client, 'switch', kernel: $kernel));
        $this->entityManager($kernel)->clear();
        $repository = $this->service($kernel, StorageProfileRepository::class);
        self::assertSame($file->getProfile()->getUuid(), $repository->activeOf(null)?->getUuid());
        self::assertNotNull($repository->targetOf(null));
        self::assertSame('files', $this->service($kernel, InstanceStorageSettings::class)->settings()?->bucket);
        self::assertSame($file->getProfile()->getUuid(), $this->entityManager($kernel)->find(StoredFile::class, $file->getId())?->getProfile()->getUuid());
        self::assertNotContains(StorageAuditEvent::MOVE_SWITCHED, $this->audit($kernel)->actions());
    }

    public function testRetiredBucketsNoFileUsesAreForgotten(): void
    {
        $kernel = $this->bootKernel();
        $used   = $this->profile($kernel, null, 'old-used', StorageProfile::STATE_RETIRED);
        $unused = $this->profile($kernel, null, 'old-unused', StorageProfile::STATE_RETIRED);
        $scoped = $this->profile($kernel, 'acme', 'acme-old', StorageProfile::STATE_RETIRED);
        $this->profile($kernel);
        $file = $this->upload($kernel);
        $this->entityManager($kernel)->getConnection()->executeStatement('UPDATE storage_kit_file SET profile_id = ? WHERE id = ?', [$used->getId(), $file->getId()]);
        $client = $this->client($kernel);

        $client->request('GET', '/admin/storage/move');
        self::assertStringContainsString('Forget previous buckets', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('1 previous bucket(s) and their credentials were forgotten.', $this->act($client, 'forget', kernel: $kernel));
        self::assertStringContainsString('Every previous bucket still holds files', $this->act($client, 'forget', kernel: $kernel));
        $repository = $this->service($kernel, StorageProfileRepository::class);
        self::assertNull($repository->findOneByUuid($unused->getUuid()));
        self::assertNotNull($repository->findOneByUuid($used->getUuid()));
        self::assertNotNull($repository->findOneByUuid($scoped->getUuid()), 'other owners are untouched');
        $events = $this->audit($kernel)->events;
        $last   = array_key_last($events);
        self::assertNotNull($last);
        $event = $events[$last];
        self::assertSame([StorageAuditEvent::PROFILES_FORGOTTEN, $unused->getUuid(), 'admin'], [$event->action, $event->details['profiles'], $event->actor]);

        // A forged token is refused.
        $client->request('POST', '/admin/storage/move/forget', ['csrf_only' => ['_token' => 'forged']]);
        $client->followRedirect();
        self::assertStringContainsString('This action is not available now.', (string) $client->getResponse()->getContent());

        // Console: dry run, one scope, every owner.
        $tester = new CommandTester((new Application($kernel))->find(ForgetRetiredProfilesCommand::NAME));
        self::assertSame(Command::INVALID, $tester->execute(['--scope' => 'acme', '--all' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--scope' => 'bad key']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--scope' => 'acme', '--dry-run' => true]));
        self::assertStringContainsString('acme-old', $tester->getDisplay());
        self::assertNotNull($repository->findOneByUuid($scoped->getUuid()));
        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true]));
        self::assertStringContainsString('1 profile(s) forgotten.', $tester->getDisplay());
        $this->entityManager($kernel)->clear();
        self::assertNull($repository->findOneByUuid($scoped->getUuid()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No retired profile to forget.', $tester->getDisplay());
    }

    public function testCancelResumeAndDiscard(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $this->upload($kernel);
        $client = $this->client($kernel);
        $this->saveTarget($client);

        // While a sync runs the target form is read-only, the AWS CSV row included.
        self::assertMatchesRegularExpression('/<input[^>]*name="storage_target\[access_key_csv\]"[^>]*disabled/', $this->act($client, 'sync'));
        self::assertStringContainsString('Copying stopped. You can resume it later.', $this->act($client, 'cancel'));
        self::assertStringContainsString('Stopped by a user', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Resume copying', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('The new bucket was forgotten.', $this->act($client, 'discard', kernel: $kernel));
        self::assertNull($this->service($kernel, StorageProfileRepository::class)->targetOf(null));

        $client->request('POST', '/admin/storage/move/sync', ['csrf_only' => ['_token' => 'forged']]);
        $client->followRedirect();
        self::assertStringContainsString('This action is not available now.', (string) $client->getResponse()->getContent());
    }

    public function testAForgedOrOtherActionTokenIsRefused(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client = $this->client($kernel);
        $this->saveTarget($client);
        $crawler = $client->request('GET', '/admin/storage/move');
        $token   = $crawler->filter('[data-testid="storage-kit-move-test"]')->closest('form')?->filter('input[type=hidden]')->attr('value');

        // The token of "test" does not authorise "discard".
        $client->request('POST', '/admin/storage/move/discard', ['csrf_only' => ['_token' => (string) $token]]);
        $client->followRedirect();
        self::assertStringContainsString('This action is not available now.', (string) $client->getResponse()->getContent());
        self::assertNotNull($this->service($kernel, StorageProfileRepository::class)->targetOf(null));
    }

    public function testWithoutMessengerTheCommandIsSuggested(): void
    {
        $kernel = $this->bootKernel(without: ['messenger']);
        $this->profile($kernel);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/storage/move');
        self::assertStringContainsString('Without a message worker', (string) $client->getResponse()->getContent());
        $this->saveTarget($client);

        self::assertStringContainsString('bin/console nowo:storage-kit:sync', $this->act($client, 'sync'));
        self::assertFalse($this->container($kernel)->has(RunStorageSyncHandler::class));
    }

    public function testAConfigurationManagedInstanceCannotSwitch(): void
    {
        $kernel = $this->bootKernel([
            'profiles'         => ['main' => ['bucket' => 'main', 'access_key' => 'k', 'secret' => 'test-secret-not-real', 'endpoint' => 'http://minio:9000']],
            'instance_profile' => 'main',
        ]);
        $client = $this->client($kernel);
        $this->saveTarget($client);
        $this->act($client, 'sync');
        $sync = $this->entityManager($kernel)->getRepository(StorageSync::class)->findOneBy([]);
        self::assertInstanceOf(StorageSync::class, $sync);
        $sync->complete();
        $this->entityManager($kernel)->flush();

        self::assertStringContainsString('managed by the configuration', $this->act($client, 'switch', kernel: $kernel));
    }

    public function testScopeMovesAndUnknownState(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $client->request('GET', '/storage/scopes/acme/move');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', '/storage/scopes/acme/move/test');
        $client->followRedirect();
        self::assertStringContainsString('This action is not available now.', (string) $client->getResponse()->getContent());

        $this->saveTarget($client, '/storage/scopes/acme/move');
        self::assertSame('/storage/scopes/acme/move', $client->getResponse()->headers->get('Location'));
        // No active scope bucket: nothing to copy from.
        self::assertStringContainsString('This action is not available now.', $this->act($client, 'sync', '/storage/scopes/acme/move', $kernel, 'scope/acme'));
        self::assertStringContainsString('Copy every file before switching.', $this->act($client, 'switch', '/storage/scopes/acme/move', $kernel, 'scope/acme'));
        self::assertStringContainsString('The new bucket was forgotten.', $this->act($client, 'discard', '/storage/scopes/acme/move', $kernel, 'scope/acme'));

        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/scopes/acme/move');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('POST', '/storage/scopes/acme/move/sync');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/storage/move/sync');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCancelWithoutAnOpenSync(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client = $this->client($kernel);
        $this->saveTarget($client);
        $crawler = $client->request('GET', '/admin/storage/move');
        self::assertCount(0, $crawler->filter('[data-testid="storage-kit-move-cancel"]'));
        $token = $this->token($kernel, $client, 'nowo_storage_kit/move/cancel/instance');
        $client->request('POST', '/admin/storage/move/cancel', ['csrf_only' => ['_token' => $token]]);
        $client->followRedirect();

        self::assertStringContainsString('This action is not available now.', (string) $client->getResponse()->getContent());
    }
}
