<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\FixedScopeResolver;
use Nowo\StorageKitBundle\Tests\Fixtures\ScopeAccess;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ErrorHandler\BufferingLogger;

use function array_keys;
use function array_map;
use function bin2hex;
use function is_array;
use function is_bool;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Look-alike context ids, source ids, owners and scopes (REQ-SEC-006, 1.5.3): `bob`, `Bob`, `bób`, `bob `, `bob\n`,
 * `bob` + NBSP and `bob` + ZWSP are seven ids. Each scenario runs on the schema the bundle generates (binary NO PAD on
 * MySQL / MariaDB) and on a folding one, as before the 1.5.3 `ALTER`: SQLite `NOCASE` / `RTRIM`, MySQL / MariaDB
 * `utf8mb4_unicode_ci` (case, accents, PAD SPACE, ignorable controls). PostgreSQL compares exactly and runs the exact
 * schema only. The look-alikes the bundle refuses at entry are seeded with SQL, as rows written before 1.5.3.
 */
final class LookalikeIdentifiersTest extends KernelTestCase
{
    /** The real id first, then its look-alikes. */
    private const LOOKALIKES = ['bob', 'Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"];

    /** Columns folded to simulate a table before the 1.5.3 `ALTER` (table => column => [type, nullable]). */
    private const FOLDED = [
        'storage_kit_file'           => ['context_id' => ['VARCHAR(64)', false], 'owner' => ['VARCHAR(64)', true]],
        'storage_kit_file_reference' => ['source_id' => ['VARCHAR(64)', false]],
        'storage_kit_profile'        => ['scope' => ['VARCHAR(64)', true]],
        'test_note'                  => ['id' => ['VARCHAR(36)', false]],
    ];

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function collations(): iterable
    {
        yield 'exact schema' => [null];
        yield 'folding: case' => ['NOCASE'];
        yield 'folding: trailing spaces' => ['RTRIM'];
    }

    #[DataProvider('collations')]
    public function testATextOnlyEverTouchesItsOwnReferences(?string $collation): void
    {
        $kernel = $this->bootKernel();
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        $files = [];
        foreach (self::LOOKALIKES as $n => $id) {
            $files[$id] = $this->seedFile($kernel, $n, 'b1');
            $this->seedReference($kernel, $files[$id]['id'], $id);
        }
        $references = $this->service($kernel, FileReferencesInterface::class);

        // Saving `bob` without its image drops its reference only.
        $references->sync('note', 'bob', 'no image any more', ['board', 'b1']);
        self::assertSame($this->hex(['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"]), $this->sources($kernel));
        self::assertNotNull($this->orphanedSince($kernel, $files['bob']['id']));
        self::assertNull($this->orphanedSince($kernel, $files['Bob']['id']), 'the look-alike keeps its file');

        // Saving it again with its image references it again; a file of another context is not taken.
        $other = $this->seedFile($kernel, 50, 'b2');
        $references->sync('note', 'bob', sprintf('![a](storage-file:%s) ![b](storage-file:%s)', $files['bob']['uuid'], $other['uuid']), ['board', 'b1']);
        self::assertSame([$files['bob']['id']], $this->filesOf($kernel, 'bob'));
        self::assertNull($this->orphanedSince($kernel, $files['bob']['id']));

        // An unusable source id stores nothing, a malformed context references nothing new.
        foreach (["bob\n", 'bob ', "bob\u{200B}", ''] as $unusable) {
            $references->sync('note', $unusable, sprintf('![a](storage-file:%s)', $other['uuid']), ['board', 'b2']);
        }
        $references->sync('note', 'fresh', sprintf('![a](storage-file:%s)', $other['uuid']), ['board', 'b 2']);
        self::assertSame([], $this->filesOf($kernel, 'fresh'));
        self::assertCount(7, $this->sources($kernel));

        // Forgetting `bob` forgets `bob` only.
        $references->forget('note', ['bob', 'nobody']);
        self::assertSame($this->hex(['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"]), $this->sources($kernel));
        $references->forget('note', []);
        $references->forget('note', ['nobody']);
        self::assertCount(6, $this->sources($kernel));
    }

    #[DataProvider('collations')]
    public function testThePurgeDropsTheReferencesOfGoneTextsByteForByte(?string $collation): void
    {
        $kernel = $this->bootKernel();
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        $files = [];
        foreach (self::LOOKALIKES as $n => $id) {
            $files[$id] = $this->seedFile($kernel, $n, 'b1');
            $this->seedReference($kernel, $files[$id]['id'], $id);
        }
        // Only the text `bob` still exists.
        $this->connection($kernel)->insert('test_note', ['id' => 'bob', 'board' => 'b1', 'body' => '']);

        $stats = $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable());

        self::assertSame(6, $stats['references']);
        self::assertSame($this->hex(['bob']), $this->sources($kernel));
        self::assertNull($this->orphanedSince($kernel, $files['bob']['id']));
        foreach (['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"] as $gone) {
            self::assertNotNull($this->orphanedSince($kernel, $files[$gone]['id']), bin2hex($gone));
        }
    }

    #[DataProvider('collations')]
    public function testThePurgeOrphansTheFilesOfGoneContextsByteForByte(?string $collation): void
    {
        $kernel = $this->bootKernel();
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        $this->service($kernel, Boards::class)->boards['bob'] = ['scope' => null, 'readers' => [], 'writers' => [], 'anonymous' => false];
        $files                                                = [];
        foreach (self::LOOKALIKES as $n => $id) {
            $files[$id] = $this->seedFile($kernel, $n, $id);
            $this->seedReference($kernel, $files[$id]['id'], 'n' . $n);
            $this->connection($kernel)->insert('test_note', ['id' => 'n' . $n, 'board' => 'b1', 'body' => '']);
        }

        $stats = $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable());

        self::assertSame(0, $stats['references']);
        self::assertSame(6, $stats['contexts']);
        self::assertSame([$files['bob']['id']], $this->filesOf($kernel, 'n0'));
        self::assertNull($this->orphanedSince($kernel, $files['bob']['id']), 'the context bob exists');
        foreach (['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"] as $gone) {
            self::assertNotNull($this->orphanedSince($kernel, $files[$gone]['id']), bin2hex($gone));
        }
    }

    #[DataProvider('collations')]
    public function testUsageCountsTheExactOwnerOnly(?string $collation): void
    {
        $kernel = $this->bootKernel();
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        foreach (self::LOOKALIKES as $n => $owner) {
            $this->seedFile($kernel, $n, 'b1', $owner, 1 << $n);
        }
        $repository = $this->service($kernel, StoredFileRepository::class);

        self::assertSame(1, $repository->bytesFor(['bob']));
        self::assertSame(3, $repository->bytesFor(['bob', 'Bob', 'bob']));
        self::assertSame(1 << 3, $repository->bytesFor(['bob ']));
        self::assertSame(0, $repository->bytesFor(['BOB']));
        self::assertSame(0, $repository->bytesFor([]));
    }

    public function testUnusableOwnerKeysAreRefusedAtEntry(): void
    {
        foreach (['bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}", '', "\u{FEFF}bob"] as $owner) {
            try {
                new FileContextTarget(null, $owner);
                self::fail(sprintf('owner "%s" accepted', bin2hex($owner)));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('bób', (new FileContextTarget(null, 'bób'))->owner);
        self::assertSame('Bob', (new FileContextTarget(null, 'Bob'))->owner);
        foreach (['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"] as $scope) {
            self::assertSame($scope === 'Bob', StorageScope::isValid($scope), bin2hex($scope));
        }
    }

    #[DataProvider('collations')]
    public function testAScopeOnlyReachesItsOwnBuckets(?string $collation): void
    {
        $kernel = $this->bootKernel(['scopes' => ['enabled' => true, 'resolver' => FixedScopeResolver::class, 'access_checker' => ScopeAccess::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(FixedScopeResolver::class)->public();
        });
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        $active = $this->profile($kernel, 'bob');
        $target = $this->profile($kernel, 'bob', 'next', StorageProfile::STATE_TARGET);
        foreach (self::LOOKALIKES as $n => $scope) {
            $this->seedProfile($kernel, $active, $n, $scope);
        }
        $profiles = $this->service($kernel, StorageProfileRepository::class);

        self::assertSame($active->getId(), $profiles->activeOf('bob')?->getId());
        self::assertSame($target->getId(), $profiles->targetOf('bob')?->getId());
        self::assertNull($profiles->activeOf('Bob'));
        self::assertNull($profiles->targetOf('Bob'));
        self::assertSame(['bob'], array_map(static fn (StorageProfile $p): ?string => $p->getScope(), $profiles->retiredOf('bob')));
        self::assertSame(['Bob'], array_map(static fn (StorageProfile $p): ?string => $p->getScope(), $profiles->retiredOf('Bob')));
        self::assertSame([], $profiles->retiredOf('BOB'));
        self::assertSame(['bob'], array_map(static fn (StorageProfile $p): ?string => $p->getScope(), $profiles->retiredUnreferenced('bob')));
        self::assertCount(7, $profiles->retiredUnreferenced(null, true));

        // Uploads: the resolver's scope reaches its own bucket, a look-alike falls back to the instance (none here),
        // a key outside the syntax turns uploads off.
        $resolver        = $this->service($kernel, FixedScopeResolver::class);
        $resolved        = $this->service($kernel, StorageProfiles::class);
        $resolver->scope = 'bob';
        self::assertSame($active->getId(), $resolved->forTarget(new FileContextTarget())?->getId());
        foreach (['Bob', 'bob ', "bob\u{200B}"] as $scope) {
            $resolved->reset();
            $resolver->scope = $scope;
            self::assertNull($resolved->forTarget(new FileContextTarget()), bin2hex($scope));
        }
    }

    #[DataProvider('collations')]
    public function testAReferenceTheFoldingKeySkipsIsLogged(?string $collation): void
    {
        $kernel = $this->bootKernel();
        if (!$this->fold($kernel, $collation)) {
            return;
        }
        // The look-alike that folds with `bob` under the collation (RTRIM ignores trailing spaces, not case).
        $holder = $collation === 'RTRIM' ? 'bob ' : 'Bob';
        $file   = $this->seedFile($kernel, 0, 'b1');
        $this->seedReference($kernel, $file['id'], $holder);
        $logger = new BufferingLogger();

        $this->references($kernel, $logger)->sync('note', 'bob', sprintf('![a](storage-file:%s)', $file['uuid']), ['board', 'b1']);

        $logs = $logger->cleanLogs();
        if ($collation === null) {
            self::assertSame([$file['id']], $this->filesOf($kernel, 'bob'), 'an exact column holds both texts');
            self::assertSame([], $logs);

            return;
        }
        self::assertSame([], $this->filesOf($kernel, 'bob'), 'never written over the look-alike\'s row');
        self::assertSame([$file['id']], $this->filesOf($kernel, $holder));
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertStringContainsString('storage_kit_file_reference ALTER', $logs[0][1]);
        self::assertSame(['skipped' => 1, 'type' => 'note'], $logs[0][2]);
    }

    /**
     * A unique violation the `NOT EXISTS` cannot foresee (a race; simulated with a folding unique index next to an
     * exact column) inside a host transaction: the insert's savepoint is rolled back, the host transaction stays usable
     * (PostgreSQL aborts a transaction on a failed statement otherwise: 25P02) and the entity manager stays open.
     */
    public function testAUniqueViolationInsideAHostTransactionLeavesItUsable(): void
    {
        $kernel     = $this->bootKernel();
        $connection = $this->connection($kernel);
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            $connection->executeStatement('CREATE UNIQUE INDEX test_folding_reference ON storage_kit_file_reference (file_id, source_type, source_id COLLATE NOCASE)');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement('CREATE UNIQUE INDEX test_folding_reference ON storage_kit_file_reference (file_id, source_type, lower(source_id))');
        } else {
            // MySQL / MariaDB cannot index a column with another collation than its own.
            $this->addToAssertionCount(1);

            return;
        }
        $file  = $this->seedFile($kernel, 0, 'b1');
        $other = $this->seedFile($kernel, 1, 'b1');
        $this->seedReference($kernel, $file['id'], 'Bob');
        $logger = new BufferingLogger();

        $connection->beginTransaction();
        $this->references($kernel, $logger)->sync('note', 'bob', sprintf('![a](storage-file:%s)', $file['uuid']), ['board', 'b1']);
        // The host keeps working in its transaction, then commits.
        $connection->executeStatement('UPDATE storage_kit_file SET total_bytes = 7 WHERE id = ?', [$other['id']]);
        $connection->commit();

        self::assertSame($this->hex(['Bob']), $this->sources($kernel));
        self::assertSame(7, (int) $connection->fetchOne('SELECT total_bytes FROM storage_kit_file WHERE id = ?', [$other['id']]));
        self::assertSame(['skipped' => 1, 'type' => 'note'], $logger->cleanLogs()[0][2]);
        self::assertTrue($this->entityManager($kernel)->isOpen());

        // Outside a transaction the insert runs in one of its own: the same outcome.
        $this->references($kernel, $logger)->sync('note', 'bob', sprintf('![a](storage-file:%s)', $file['uuid']), ['board', 'b1']);
        self::assertSame($this->hex(['Bob']), $this->sources($kernel));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testTheIdentifierColumnsUseABinaryNoPadCollationOnMysqlAndMariadb(): void
    {
        $kernel     = $this->bootKernel();
        $connection = $this->connection($kernel);
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addToAssertionCount(1);

            return;
        }
        $collations = $connection->fetchAllAssociative(
            "SELECT table_name AS t, column_name AS c, collation_name AS collation FROM information_schema.columns WHERE table_schema = DATABASE()
             AND ((table_name = 'storage_kit_file' AND column_name IN ('context_id', 'owner')) OR (table_name = 'storage_kit_file_reference' AND column_name = 'source_id') OR (table_name = 'storage_kit_profile' AND column_name = 'scope'))",
        );
        self::assertCount(4, $collations);
        foreach ($collations as $row) {
            self::assertContains($row['collation'], ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin'], sprintf('%s.%s', $row['t'], $row['c']));
        }
    }

    /**
     * Gives the identifier columns a folding collation, as tables created before 1.5.3 have; false when the platform
     * has none to give (PostgreSQL: the folding cases are skipped there).
     */
    private function fold(StorageKitTestKernel $kernel, ?string $collation): bool
    {
        if ($collation === null) {
            return true;
        }
        $connection = $this->connection($kernel);
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            foreach (self::FOLDED as $table => $columns) {
                foreach ($columns as $column => [$type, $nullable]) {
                    $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s', $table, $column, $type, $nullable ? 'NULL' : 'NOT NULL'));
                }
            }

            return true;
        }
        if (!$platform instanceof SQLitePlatform) {
            $this->addToAssertionCount(1);

            return false;
        }
        // SQLite cannot change a column: re-create the (still empty) tables with the collation, then their indexes.
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (self::FOLDED as $table => $columns) {
            $sql = $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
            self::assertIsString($sql);
            /** @var list<string> $indexes */
            $indexes = $connection->fetchFirstColumn("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
            foreach (array_keys($columns) as $column) {
                $sql = (string) preg_replace('/\b(' . $column . ' VARCHAR\(\d+\))/', '$1 COLLATE ' . $collation, $sql, 1);
            }
            $connection->executeStatement('DROP TABLE ' . $table);
            $connection->executeStatement($sql);
            foreach ($indexes as $index) {
                $connection->executeStatement($index);
            }
            self::assertStringContainsString('COLLATE ' . $collation, (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]));
        }
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return true;
    }

    /**
     * A copy of an uploaded file with the given context id and owner (inserted with SQL, as a row before 1.5.3).
     *
     * @return array{id: int, uuid: string}
     */
    private function seedFile(StorageKitTestKernel $kernel, int $n, string $contextId, ?string $owner = null, int $bytes = 1): array
    {
        $connection = $this->connection($kernel);
        $base       = $connection->fetchAssociative('SELECT * FROM storage_kit_file ORDER BY id LIMIT 1');
        if (!is_array($base)) {
            if ($connection->fetchOne('SELECT COUNT(*) FROM storage_kit_profile WHERE scope IS NULL') == 0) {
                $this->profile($kernel);
            }
            $view = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new AppUser(1, 'alice'), 'board', 'b1', Images::png(4, 4), 'seed.png');
            $base = $connection->fetchAssociative('SELECT * FROM storage_kit_file WHERE uuid = ?', [$view->uuid]);
            self::assertIsArray($base);
            $connection->executeStatement('DELETE FROM storage_kit_file');
        }
        unset($base['id']);
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $n + 1);
        $connection->insert('storage_kit_file', [
            'uuid'           => $uuid,
            'storage_key'    => sprintf('probe/%d', $n),
            'context_type'   => 'board',
            'context_id'     => $contextId,
            'owner'          => $owner,
            'total_bytes'    => $bytes,
            'orphaned_since' => null,
        ] + $base);

        return ['id' => (int) $connection->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ?', [$uuid]), 'uuid' => $uuid];
    }

    private function seedReference(StorageKitTestKernel $kernel, int $fileId, string $sourceId): void
    {
        $this->connection($kernel)->insert('storage_kit_file_reference', [
            'file_id'     => $fileId,
            'source_type' => 'note',
            'source_id'   => $sourceId,
            'created_at'  => '2026-11-13 00:00:00',
        ]);
    }

    /**
     * A retired copy of `$profile` for the scope `$scope` (inserted with SQL, as a row before 1.5.3).
     */
    private function seedProfile(StorageKitTestKernel $kernel, StorageProfile $profile, int $n, string $scope): void
    {
        $connection = $this->connection($kernel);
        $row        = $connection->fetchAssociative('SELECT * FROM storage_kit_profile WHERE id = ?', [$profile->getId()]);
        self::assertIsArray($row);
        unset($row['id']);
        $row = ['uuid' => sprintf('00000000-0000-4000-9000-%012d', $n + 1), 'scope' => $scope, 'state' => StorageProfile::STATE_RETIRED] + $row;
        // PostgreSQL returns booleans as PHP booleans: bind them as such (a string `false` is "").
        $types = array_map(static fn (mixed $value): ParameterType => is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING, $row);
        $connection->insert('storage_kit_profile', $row, $types);
    }

    /**
     * @return list<string> the source ids left, hex, sorted
     */
    private function sources(StorageKitTestKernel $kernel): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection($kernel)->fetchFirstColumn('SELECT source_id FROM storage_kit_file_reference');

        return $this->hex($ids);
    }

    /**
     * @return list<int> the files referenced by exactly `$sourceId`
     */
    private function filesOf(StorageKitTestKernel $kernel, string $sourceId): array
    {
        $ids = [];
        foreach ($this->connection($kernel)->fetchAllAssociative('SELECT file_id, source_id FROM storage_kit_file_reference ORDER BY file_id') as $row) {
            if ($row['source_id'] === $sourceId) {
                $ids[] = (int) $row['file_id'];
            }
        }

        return $ids;
    }

    private function orphanedSince(StorageKitTestKernel $kernel, int $fileId): mixed
    {
        return $this->connection($kernel)->fetchOne('SELECT orphaned_since FROM storage_kit_file WHERE id = ?', [$fileId]);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> hex, sorted
     */
    private function hex(array $values): array
    {
        $hex = array_map(bin2hex(...), $values);
        sort($hex);

        return $hex;
    }

    private function references(StorageKitTestKernel $kernel, BufferingLogger $logger): FileReferences
    {
        return new FileReferences($this->service($kernel, StorageEntityManager::class), $this->service($kernel, FileUris::class), $logger);
    }

    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->entityManager($kernel)->getConnection();
    }
}
