<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\StorageKitBundle\Command\BackfillUploaderKeysCommand;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalData;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\PrefixedUploaderKeys;
use Nowo\StorageKitBundle\Tests\Fixtures\Users\AppUsers;
use Nowo\StorageKitBundle\Tests\Fixtures\Users\CaseInsensitiveAppUsers;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_column;
use function array_map;
use function bin2hex;
use function preg_replace;
use function sort;
use function sprintf;
use function str_repeat;

/**
 * Look-alike uploader identifiers and keys (PAD SPACE / case- and accent-folding collations, house rule LQ): `alice`,
 * `Alice`, `alice ` and `álice` are four people. On MySQL / MariaDB with the host's default collation (`*_ci`, and
 * PAD SPACE on `utf8mb4_unicode_ci` / `utf8mb4_general_ci` / MariaDB `_bin`) they compare equal in SQL, so the GDPR
 * export and erasure of one reached the files of the others and the backfill stamped their files with one person's
 * key. Every lookup is now re-checked with `===` in PHP and the identity columns use a binary NO PAD collation.
 *
 * Runs on every CI database (SQLite, MySQL, MariaDB, PostgreSQL); the look-alikes are seeded with SQL, as rows written
 * before 1.5.2 (uploads no longer store a padded identifier).
 */
final class LookalikeUploaderTest extends KernelTestCase
{
    /** The real person and three look-alikes, all without key. */
    private const IDENTIFIERS = ['alice', 'Alice', 'alice ', 'álice'];

    public function testTheExportAndTheErasureByIdentifierOnlyReachTheExactIdentifier(): void
    {
        $kernel = $this->bootKernel();
        $this->seed($kernel, array_map(static fn (string $identifier): array => [$identifier, null], self::IDENTIFIERS));
        $data = $this->service($kernel, StoredFilesPersonalDataInterface::class);

        foreach (self::IDENTIFIERS as $identifier) {
            self::assertSame([$this->nameOf($identifier)], array_column($data->export($identifier), 'name'), sprintf('export of "%s"', $identifier));
        }
        self::assertSame(1, $data->erase('Alice'));
        self::assertSame(['alice', 'alice ', 'álice'], $this->uploaders($kernel), 'erasing Alice leaves alice, "alice " and álice');
        self::assertSame(1, $data->erase('alice '));
        self::assertSame(['alice', 'álice'], $this->uploaders($kernel));
    }

    public function testTheExportAndTheErasureByKeyReachTheExactKey(): void
    {
        $kernel = $this->bootKernel();
        $this->seed($kernel, [['alice', 'App:abc'], ['Alice', 'App:ABC'], ['alice2', 'App:abc '], ['bob', null], ['Bob', null]]);
        $data = $this->service($kernel, StoredFilesPersonalDataInterface::class);

        self::assertSame([$this->nameOf('alice')], array_column($data->export('nobody', 'App:abc'), 'name'));
        self::assertSame([$this->nameOf('bob')], array_column($data->export('bob', 'App:bob'), 'name'), 'keyless rows by the exact identifier only');
        self::assertSame(1, $data->erase('nobody', 'App:ABC'));
        self::assertSame(['Bob', 'alice', 'alice2', 'bob'], $this->uploaders($kernel));
    }

    public function testAPageOfLookalikesDoesNotEndTheWalkBeforeTheRealFiles(): void
    {
        $kernel = $this->bootKernel();
        $rows   = [];
        for ($i = 0; $i <= StoredFilesPersonalData::PAGE; ++$i) {
            $rows[] = ['Alice', null];
        }
        $rows[] = ['alice', null];
        $this->seed($kernel, $rows);
        $data = $this->service($kernel, StoredFilesPersonalDataInterface::class);

        self::assertCount(1, $data->export('alice'));
        self::assertSame(1, $data->erase('alice'));
        self::assertCount(StoredFilesPersonalData::PAGE + 1, $data->export('Alice'));
    }

    public function testTheBackfillNeverStampsALookalikeWithAnotherPersonsKey(): void
    {
        $kernel = $this->bootKernel(['personal_data' => ['user_provider' => AppUsers::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(AppUsers::class);
        });
        $this->seed($kernel, [['Alice', null], ['alice', null], ['alice ', null], ['bob', null]]);
        $tester = new CommandTester((new Application($kernel))->find(BackfillUploaderKeysCommand::NAME));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $keys = $this->entityManager($kernel)->getConnection()->fetchAllKeyValue('SELECT original_name, uploaded_by_key FROM storage_kit_file WHERE original_name LIKE ?', ['probe-%']);
        self::assertSame(AppUser::class . ':7', $keys[$this->nameOf('alice')]);
        self::assertSame(AppUser::class . ':8', $keys[$this->nameOf('bob')]);
        self::assertNull($keys[$this->nameOf('Alice')], 'the provider does not know Alice');
        self::assertNull($keys[$this->nameOf('alice ')], 'the provider does not know "alice "');
    }

    public function testTheBackfillDoesNotUseAUserLoadedUnderAnotherIdentifier(): void
    {
        $kernel = $this->bootKernel(['personal_data' => ['user_provider' => CaseInsensitiveAppUsers::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(CaseInsensitiveAppUsers::class);
        });
        $this->seed($kernel, [['alice', null], ['ALICE', null], ['Alice', null], ['bob', null]]);
        $tester = new CommandTester((new Application($kernel))->find(BackfillUploaderKeysCommand::NAME));
        $tester->execute(['--dry-run' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('alice → ' . AppUser::class . ':7', $tester->getDisplay());
        self::assertStringNotContainsString('Alice →', $tester->getDisplay());
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Loaded by the provider under another identifier, left without key: 2.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $keys = $this->entityManager($kernel)->getConnection()->fetchAllKeyValue('SELECT original_name, uploaded_by_key FROM storage_kit_file WHERE original_name LIKE ?', ['probe-%']);
        self::assertSame(AppUser::class . ':7', $keys[$this->nameOf('alice')]);
        self::assertSame(AppUser::class . ':8', $keys[$this->nameOf('bob')]);
        self::assertNull($keys[$this->nameOf('ALICE')]);
        self::assertNull($keys[$this->nameOf('Alice')]);
    }

    public function testUploadsNeverStoreAPaddedOrOverlongIdentifierOrKey(): void
    {
        $kernel = $this->bootKernel(['personal_data' => ['uploader_key_resolver' => PrefixedUploaderKeys::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(PrefixedUploaderKeys::class);
        });
        $this->profile($kernel);
        $long                                                           = str_repeat('a', 181);
        $this->service($kernel, Boards::class)->boards['b1']['writers'] = ['alice', 'alice ', "ali\u{200B}ce", $long];
        $uploader                                                       = $this->service($kernel, ImageUploader::class);

        $file = $uploader->upload(new AppUser(5, 'alice'), 'board', 'b1', Images::png(4, 4), 'a.png');
        self::assertSame('alice', $file->getUploadedBy());
        self::assertSame('user:5', $file->getUploadedByKey());
        foreach (['alice ', "ali\u{200B}ce", $long] as $identifier) {
            $file = $uploader->upload(new AppUser('5 ', $identifier), 'board', 'b1', Images::png(4, 4), 'a.png');
            self::assertNull($file->getUploadedBy(), sprintf('identifier "%s"', $identifier));
            self::assertNull($file->getUploadedByKey(), 'a padded key is not stored either');
        }
    }

    public function testTheIdentityColumnsUseABinaryNoPadCollationOnMysqlAndMariadb(): void
    {
        $kernel     = $this->bootKernel();
        $connection = $this->entityManager($kernel)->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addToAssertionCount(1);

            return;
        }
        $collations = $connection->fetchAllKeyValue("SELECT column_name, collation_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'storage_kit_file' AND column_name IN ('uploaded_by', 'uploaded_by_key') ORDER BY column_name");
        self::assertCount(2, $collations);
        foreach ($collations as $collation) {
            self::assertContains($collation, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin']);
        }
    }

    /**
     * One uploaded file copied once per row, with the given uploader identifier and key.
     *
     * @param list<array{0: string, 1: string|null}> $rows
     */
    private function seed(StorageKitTestKernel $kernel, array $rows): void
    {
        $this->profile($kernel);
        $view       = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new AppUser(1, 'alice'), 'board', 'b1', Images::png(4, 4), 'seed.png');
        $connection = $this->connection($kernel);
        $base       = $connection->fetchAssociative('SELECT * FROM storage_kit_file WHERE uuid = ?', [$view->uuid]);
        self::assertIsArray($base);
        unset($base['id']);
        $connection->executeStatement('DELETE FROM storage_kit_file');
        $seen = [];
        foreach ($rows as $n => [$identifier, $key]) {
            $name              = isset($seen[$identifier]) ? sprintf('%s-%d', $this->nameOf($identifier), $n) : $this->nameOf($identifier);
            $seen[$identifier] = true;
            $connection->insert('storage_kit_file', [
                'uuid'            => sprintf('00000000-0000-4000-8000-%012d', $n + 1),
                'storage_key'     => sprintf('probe/%d', $n),
                'original_name'   => $name,
                'uploaded_by'     => $identifier,
                'uploaded_by_key' => $key,
            ] + $base);
        }
    }

    /**
     * The original name a seeded row carries: `probe-` and the hex of the identifier (look-alike names fold too).
     */
    private function nameOf(string $identifier): string
    {
        return 'probe-' . bin2hex($identifier);
    }

    /**
     * @return list<string> the uploader identifiers left, sorted byte-wise
     */
    private function uploaders(StorageKitTestKernel $kernel): array
    {
        /** @var list<string> $left */
        $left = array_map('strval', $this->connection($kernel)->fetchFirstColumn('SELECT uploaded_by FROM storage_kit_file WHERE uploaded_by IS NOT NULL'));
        sort($left);

        return $left;
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->entityManager($kernel)->getConnection();
    }
}
