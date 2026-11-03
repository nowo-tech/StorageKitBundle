<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Command\AdoptInstanceBucketCommand;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `nowo:storage-kit:adopt-instance-bucket`: an imported database instance row becomes the settings-backed active row,
 * its connection moves into the `storage` section (secret never printed), idempotent, --dry-run and --force.
 */
final class AdoptInstanceBucketTest extends KernelTestCase
{
    private function tester(StorageKitTestKernel $kernel): CommandTester
    {
        return new CommandTester((new Application($kernel))->find(AdoptInstanceBucketCommand::NAME));
    }

    public function testAnImportedInstanceRowIsAdopted(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel);
        $tester  = $this->tester($kernel);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertNull($this->service($kernel, InstanceStorageSettings::class)->provider());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('test-secret-not-real', $tester->getDisplay());
        $this->entityManager($kernel)->clear();
        $active = $this->service($kernel, StorageProfileRepository::class)->activeOf(null);
        self::assertSame($profile->getUuid(), $active?->getUuid());
        self::assertTrue($active->isSettingsBacked());
        self::assertNull($active->getSecret());
        $settings = $this->service($kernel, InstanceStorageSettings::class)->settings();
        self::assertSame(['files', 'test-secret-not-real', 'http://minio:9000'], [$settings?->bucket, $settings?->secret, $settings?->endpoint]);
        self::assertContains(StorageAuditEvent::PROFILE_SAVED, $this->audit($kernel)->actions());

        // Idempotent.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('already in the "storage" section', $tester->getDisplay());
    }

    public function testNothingToAdopt(): void
    {
        $kernel = $this->bootKernel();
        $tester = $this->tester($kernel);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('no instance bucket in the profile table', $tester->getDisplay());
    }

    public function testAnIncompleteRowOrAConfiguredSectionIsRefused(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel);
        $profile->setSecret(null);
        $this->entityManager($kernel)->flush();
        $tester = $this->tester($kernel);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('is incomplete', $tester->getDisplay());

        $profile->setSecret('test-secret-not-real');
        $this->entityManager($kernel)->flush();
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => StorageProvider::AWS_S3, 'region' => 'eu-west-1', 'bucket' => 'other', 'access_key' => 'k', 'secret' => 's']);
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('--force', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame('files', $this->service($kernel, InstanceStorageSettings::class)->settings()?->bucket);
    }

    public function testAConnectionTheSectionRefusesChangesNothing(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel, endpoint: 'http://10.0.0.5:9000');
        $tester  = $this->tester($kernel);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('fields: endpoint', $tester->getDisplay());
        self::assertSame(StorageProfile::SOURCE_DATABASE, $profile->getSource());
        self::assertNull($this->service($kernel, InstanceStorageSettings::class)->provider());
    }
}
