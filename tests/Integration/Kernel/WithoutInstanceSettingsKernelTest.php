<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Command\AdoptInstanceBucketCommand;
use Nowo\StorageKitBundle\Command\PurgeFilesCommand;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\EventListener\InstanceSettingsListener;
use Nowo\StorageKitBundle\Form\Extension\StorageSettingsSectionTypeExtension;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Lifecycle\RemovedFile;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function getenv;
use function interface_exists;

/**
 * 2.0 (REQ-ARCH-001 rule 8): nowo-tech/instance-settings-bundle is a suggested package. Every test boots without its
 * bundle, so the file also runs in the "Without Instance Settings" CI job, where the package is not installed at all
 * (STORAGE_KIT_TEST_WITHOUT_IS=1 then asserts it is really gone):
 *
 * - a config-pinned instance bucket boots, lints, uploads, delivers, renders the Storage page, purges and removes,
 *   and no class typed against Instance Settings is registered (none is autoloaded);
 * - the settings-backed instance bucket (`instance_profile: null`, the default) fails the build with
 *   `composer require nowo-tech/instance-settings-bundle` first in the message, never silently (lesson XQ-2).
 */
final class WithoutInstanceSettingsKernelTest extends KernelTestCase
{
    /** The instance bucket managed by the configuration (no Instance Settings section), streamed by the application. */
    private const PINNED = ['profiles' => ['main' => ['dsn' => 's3://test-key:test-secret-not-real@minio:9000/main?tls=0', 'delivery' => 'proxy']], 'instance_profile' => 'main'];

    public function testThePackageIsReallyAbsentInTheWithoutJob(): void
    {
        if (getenv('STORAGE_KIT_TEST_WITHOUT_IS') !== '1') {
            self::assertTrue(interface_exists(InstanceSettingsInterface::class), 'the full suite runs with the package (require-dev)');

            return;
        }
        self::assertFalse(interface_exists(InstanceSettingsInterface::class), 'composer remove --dev nowo-tech/instance-settings-bundle ran');
    }

    public function testAPinnedBucketWorksWithoutInstanceSettings(): void
    {
        $kernel = $this->bootKernel(self::PINNED, ['settings'], debug: true);
        foreach ([InstanceStorageSettings::class, StorageSettingsSection::class, InstanceSettingsListener::class, StorageSettingsSectionTypeExtension::class, AdoptInstanceBucketCommand::class] as $id) {
            self::assertFalse($this->container($kernel)->has($id), $id . ' is registered only with the settings-backed bucket');
        }
        self::assertTrue($this->service($kernel, StorageEntityManager::class)->isPrivate());

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $lint = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $lint->execute([]), $lint->getDisplay());
        self::assertFalse($application->has(AdoptInstanceBucketCommand::NAME));

        $file   = $this->uploadFile($kernel, new InMemoryUser('alice', null), 'board', 'b1', Images::png(20, 20), 'a.png');
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/' . $file->getUuid() . '/original');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->headers->get('Location'));

        $admin = $this->client($kernel);
        $admin->request('GET', '/admin/storage');
        self::assertSame(200, $admin->getResponse()->getStatusCode(), (string) $admin->getResponse()->getContent());

        self::assertInstanceOf(RemovedFile::class, $this->service($kernel, FileRemoverInterface::class)->expire($file->getUuid(), 'board', 'b1'));
        $purge = new CommandTester($application->find(PurgeFilesCommand::NAME));
        self::assertSame(0, $purge->execute(['--days' => '0']), $purge->getDisplay());
        self::assertSame(0, (int) $this->service($kernel, StorageEntityManager::class)->connection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
    }

    public function testTheSettingsBackedBucketNeedsInstanceSettings(): void
    {
        try {
            $this->bootKernel([], ['settings']);
            self::fail('accepted');
        } catch (LogicException $e) {
            self::assertStringContainsString('nowo-tech/instance-settings-bundle', $e->getMessage());
            if (!interface_exists(InstanceSettingsInterface::class)) {
                // Not installed (a host that got it transitively from 1.x and ran composer update).
                self::assertStringStartsWith('composer require nowo-tech/instance-settings-bundle: ', $e->getMessage());
            }
        }
    }
}
