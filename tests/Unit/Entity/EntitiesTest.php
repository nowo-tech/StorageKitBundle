<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class EntitiesTest extends TestCase
{
    public function testAProfileKeepsItsSecretWhenTheFieldIsLeftBlank(): void
    {
        $profile = new StorageProfile('acme');
        self::assertFalse($profile->isComplete());
        self::assertSame(StorageProfile::SOURCE_DATABASE, $profile->getSource());
        self::assertFalse($profile->isEnabled());

        $profile->configure(true, ' https://s3.example.com ', '', '', ' files ', '/app/', ' key ', 'test-secret-not-real', true);
        $profile->configure(true, 'https://s3.example.com', null, 'eu-west-1', 'files', 'app', 'key', '  ', true);

        self::assertTrue($profile->isComplete());
        self::assertTrue($profile->hasSecret());
        self::assertSame('test-secret-not-real', $profile->getSecret());
        self::assertSame(['https://s3.example.com', null, 'eu-west-1', 'files', 'app', 'key', true], [$profile->getEndpoint(), $profile->getPublicEndpoint(), $profile->getRegion(), $profile->getBucket(), $profile->getPrefix(), $profile->getAccessKey(), $profile->isPathStyle()]);
        self::assertTrue($profile->settings(true)->restricted);
        self::assertSame('acme', $profile->getScope());
        self::assertNull($profile->getId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $profile->getUuid());
        self::assertLessThanOrEqual($profile->getUpdatedAt(), $profile->getCreatedAt());

        $profile->setSecret(null);
        self::assertFalse($profile->hasSecret());
        $profile->recordTest(true, true);
        self::assertTrue($profile->getTestOk());
        self::assertTrue($profile->isTestPublicRead());
        self::assertNotNull($profile->getTestedAt());
    }

    public function testStatesAndConfigRows(): void
    {
        $target = new StorageProfile(null, StorageProfile::STATE_TARGET);
        self::assertTrue($target->isTarget());
        self::assertFalse($target->isActive());
        $target->activate();
        self::assertTrue($target->isActive());
        self::assertTrue($target->isEnabled());
        $target->retire();
        self::assertSame(StorageProfile::STATE_RETIRED, $target->getState());

        $config = new StorageProfile(null, StorageProfile::STATE_ACTIVE, 'main');
        self::assertSame(StorageProfile::SOURCE_CONFIG, $config->getSource());
        self::assertSame('main', $config->getConfigName());
        self::assertTrue($config->isComplete());
        self::assertTrue($config->isEnabled());

        $this->expectException(InvalidArgumentException::class);
        new StorageProfile('bad/scope');
    }

    public function testAStoredFileAndItsVariants(): void
    {
        $file = new StoredFile(new StorageProfile(), 'board', 'b1', '2026/10/u', 'photo.png', 'image/png', 100, 2000, 1000, 'abc', '0b6f2a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');
        $file->addVariant('preview', ['key' => '2026/10/u-preview', 'size' => 40, 'width' => 1600, 'height' => 800, 'mime' => 'image/png']);

        self::assertSame(140, $file->totalBytes());
        self::assertSame(['key' => '2026/10/u-preview', 'mime' => 'image/png', 'width' => 1600, 'height' => 800], $file->variant('preview'));
        self::assertSame(['key' => '2026/10/u', 'mime' => 'image/png', 'width' => 2000, 'height' => 1000], $file->variant('thumb'));
        self::assertSame(['2026/10/u', '2026/10/u-preview'], $file->objectKeys());
        self::assertSame('0b6f2a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b', $file->getUuid());
        self::assertSame(['board', 'b1', '2026/10/u', 'photo.png', 'image/png', 100, 2000, 1000, 'abc'], [$file->getContextType(), $file->getContextId(), $file->getStorageKey(), $file->getOriginalName(), $file->getMimeType(), $file->getSize(), $file->getWidth(), $file->getHeight(), $file->getSha256()]);
        self::assertCount(1, $file->getVariants());
        self::assertNull($file->getId());

        $file->setUploadedBy('alice');
        $file->setOwner('owner-1');
        self::assertSame(['alice', 'owner-1'], [$file->getUploadedBy(), $file->getOwner()]);
        $first = new DateTimeImmutable('2026-01-01');
        $file->markOrphaned($first);
        $file->markOrphaned(new DateTimeImmutable('2026-02-01'));
        self::assertEquals($first, $file->getOrphanedSince());
        $file->expireOrphan(new DateTimeImmutable('2000-01-01'));
        self::assertSame('2000', $file->getOrphanedSince()?->format('Y'));
        $file->anonymise();
        self::assertSame([StoredFile::ANONYMOUS_NAME, null], [$file->getOriginalName(), $file->getUploadedBy()]);

        $reference = new StoredFileReference($file, 'note', 'n1');
        self::assertSame([$file, 'note', 'n1', null], [$reference->getFile(), $reference->getSourceType(), $reference->getSourceId(), $reference->getId()]);
    }

    public function testASyncRecordsItsProgress(): void
    {
        $sync = new StorageSync($from = new StorageProfile(), $to = new StorageProfile(null, StorageProfile::STATE_TARGET));
        self::assertSame([$from, $to, StorageSync::QUEUED, 0, 0], [$sync->getFrom(), $sync->getTo(), $sync->getStatus(), $sync->percent(), $sync->getCursor()]);
        self::assertTrue($sync->isOpen());

        $sync->start(4);
        $sync->advance(7, 2, 1);
        self::assertSame([StorageSync::RUNNING, 7, 1, 4, 25, 2, 1], [$sync->getStatus(), $sync->getCursor(), $sync->getProcessed(), $sync->getTotal(), $sync->percent(), $sync->getCopiedObjects(), $sync->getSkippedObjects()]);
        $sync->fail(str_repeat('e', 600));
        self::assertSame(StorageSync::ERROR_MAX, mb_strlen((string) $sync->getError()));
        self::assertNotNull($sync->getFinishedAt());
        $sync->queue();
        self::assertNull($sync->getError());
        $sync->cancel();
        self::assertSame(StorageSync::CANCELLED, $sync->getStatus());
        self::assertFalse($sync->isOpen());

        $empty = new StorageSync(new StorageProfile(), new StorageProfile());
        $empty->complete();
        self::assertSame(100, $empty->percent());
        self::assertTrue($empty->isCompleted());
        $empty->setStartedBy('admin');
        self::assertSame('admin', $empty->getStartedBy());
        self::assertNull($empty->getId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $empty->getUuid());
        self::assertLessThanOrEqual($empty->getUpdatedAt(), $empty->getCreatedAt());
    }
}
