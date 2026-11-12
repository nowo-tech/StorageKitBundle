<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\EventListener\InstanceSettingsListener;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalData;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Tests\Support\Legacy\InstanceSettingsListenerV140;
use Nowo\StorageKitBundle\Tests\Support\Legacy\StoredFilesPersonalDataV140;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_map;
use function count;
use function is_scalar;
use function ksort;
use function sprintf;

/**
 * 1.5 equivalence of the two host-called writes that flushed the entity manager up to 1.4 and are DBAL statements now
 * (REQ-ARCH-001 rule 6): the GDPR erasure ({@see StoredFilesPersonalData::erase()}) and the `storage` settings
 * listener ({@see InstanceSettingsListener}). The same seeded database run through the v1.4.0 code
 * ({@see StoredFilesPersonalDataV140}, {@see InstanceSettingsListenerV140}, literal copies) and through the 1.5 code
 * ends with the same rows (every column of the files, references and profile tables), the same return values and the
 * same audit events — over more than one erasure page, by identifier and by uploader key, with referenced and
 * unreferenced files. The one intended difference: an entity the host left pending stays pending (1.4 flushed it).
 *
 * Runs on every CI database (SQLite, MySQL, MariaDB, PostgreSQL): one kernel at a time, schema recreated per boot.
 */
final class HostWritesEquivalenceTest extends KernelTestCase
{
    /** Rows seeded besides the uploaded one: more than {@see StoredFilesPersonalData::PAGE} for alice. */
    private const ROWS = 1400;

    /** The erasures run in order: [identifier, key or null]. */
    private const ERASURES = [['alice', null], ['bob', 'App:9'], ['carol', 'App:3'], ['nobody', null]];

    public function testTheErasureEndsWithTheSameRowsAsVersion140(): void
    {
        $legacy = $this->eraseRun(true);
        $new    = $this->eraseRun(false);

        self::assertSame($legacy['counts'], $new['counts']);
        self::assertGreaterThan(StoredFilesPersonalData::PAGE, $new['counts'][0], 'alice crosses an erasure page');
        self::assertSame($legacy['files'], $new['files']);
        self::assertSame($legacy['references'], $new['references']);
        self::assertSame($legacy['audit'], $new['audit']);
        self::assertCount(self::ROWS + 1, $new['files']);

        // The intended difference: 1.4 flushed the host's pending entity, 1.5 leaves it pending.
        self::assertSame(1, $legacy['notes']);
        self::assertSame(0, $new['notes']);
        self::assertTrue($new['pending']);
    }

    public function testTheSettingsListenerEndsWithTheSameProfileRowAsVersion140(): void
    {
        $legacy = $this->settingsRun(true);
        $new    = $this->settingsRun(false);

        self::assertSame($legacy['profile'], $new['profile']);
        self::assertNull($new['profile']['tested_at']);
        self::assertSame($legacy['audit'], $new['audit']);
        self::assertSame(1, $legacy['notes']);
        self::assertSame(0, $new['notes']);
        self::assertTrue($new['pending']);
        self::assertTrue($new['synced'], 'the managed profile shows the forgotten test, with nothing left to flush');
    }

    /**
     * @return array{counts: list<int>, files: list<array<string, scalar|null>>, references: list<array<string, scalar|null>>, audit: list<array<string, mixed>>, notes: int, pending: bool}
     */
    private function eraseRun(bool $legacy): array
    {
        $kernel     = $this->bootKernel();
        $connection = $this->seed($kernel);
        $manager    = $this->entityManager($kernel);
        $manager->clear();
        $this->audit($kernel)->events = [];
        $note                         = new Note('b1', 'pending host change');
        $manager->persist($note);

        $loaded = $this->service($kernel, StoredFileRepository::class)->uploadedBy('alice', 0, 1)[0]['file'];
        $data   = $legacy
            ? new StoredFilesPersonalDataV140(
                $this->service($kernel, StoredFileRepository::class),
                $this->service($kernel, StorageEntityManager::class),
                $this->service($kernel, FileUrls::class),
                $this->service($kernel, AuditRecorder::class),
                $this->service($kernel, UploaderKeyResolverInterface::class),
            )
            : $this->service($kernel, StoredFilesPersonalDataInterface::class);
        $counts = [];
        foreach (self::ERASURES as [$identifier, $key]) {
            $counts[] = $data->erase($identifier, $key);
        }
        $pending = $manager->getUnitOfWork()->isScheduledForInsert($note);
        if (!$legacy) {
            // The file the manager held shows the erased values and has nothing left to flush.
            self::assertSame(StoredFile::ANONYMOUS_NAME, $loaded->getOriginalName());
            self::assertNull($loaded->getUploadedBy());
            $manager->getUnitOfWork()->computeChangeSets();
            self::assertSame([], $manager->getUnitOfWork()->getEntityChangeSet($loaded));
        }

        return [
            'counts'     => $counts,
            'files'      => $this->rows($connection, $manager->getClassMetadata(StoredFile::class)->getTableName()),
            'references' => $this->rows($connection, $manager->getClassMetadata(StoredFileReference::class)->getTableName()),
            'audit'      => $this->auditOf($kernel),
            'notes'      => (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $manager->getClassMetadata(Note::class)->getTableName()),
            'pending'    => $pending,
        ];
    }

    /**
     * @return array{profile: array<string, scalar|null>, audit: list<array<string, mixed>>, notes: int, pending: bool, synced: bool}
     */
    private function settingsRun(bool $legacy): array
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->alias(InstanceSettingsListener::class . '.public', InstanceSettingsListener::class)->public();
        });
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => StorageProvider::S3_COMPATIBLE, 'endpoint' => 'http://minio:9000', 'region' => 'us-east-1', 'bucket' => 'files', 'access_key' => 'test-key', 'secret' => 'REDACTED-TEST', 'path_style' => true]);
        $profiles = $this->service($kernel, StorageProfiles::class);
        $profiles->reset();
        $active = $profiles->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $active);
        self::assertTrue($active->isSettingsBacked());
        $active->recordTest(true, true);
        $manager = $this->entityManager($kernel);
        $manager->flush();
        $connection = $manager->getConnection();
        $table      = $manager->getClassMetadata(StorageProfile::class)->getTableName();
        $connection->executeStatement('UPDATE ' . $table . ' SET tested_at = ?, created_at = ?, updated_at = ?, uuid = ?', ['2026-01-02 03:04:05', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '00000000-0000-4000-8000-0000000000aa']);
        $manager->clear();
        $profiles->reset();
        $this->audit($kernel)->events = [];
        $note                         = new Note('b1', 'pending host change');
        $manager->persist($note);

        $listener = $legacy
            ? new InstanceSettingsListenerV140(
                $profiles,
                $this->service($kernel, FileStorage::class),
                $this->service($kernel, StorageEntityManager::class),
                $this->service($kernel, AuditRecorder::class),
            )
            : $this->service($kernel, InstanceSettingsListener::class . '.public');
        self::assertIsCallable($listener);
        $listener(new SettingsChangedEvent('storage', ['bucket', 'secret'], ['secret'], SettingsChangedEvent::ORIGIN_FORM));
        $pending = $manager->getUnitOfWork()->isScheduledForInsert($note);
        $active  = $profiles->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $active);
        $manager->getUnitOfWork()->computeChangeSets();
        $synced = !$active->getTestedAt() instanceof DateTimeImmutable && $manager->getUnitOfWork()->getEntityChangeSet($active) === [];
        $rows   = $this->rows($connection, $table);
        self::assertCount(1, $rows);

        return [
            'profile' => $rows[0],
            'audit'   => $this->auditOf($kernel),
            'notes'   => (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $manager->getClassMetadata(Note::class)->getTableName()),
            'pending' => $pending,
            'synced'  => $synced,
        ];
    }

    /**
     * One uploaded file copied {@see self::ROWS} times with deterministic uploaders, keys, names, dates and references.
     */
    private function seed(StorageKitTestKernel $kernel): Connection
    {
        $this->profile($kernel);
        $view       = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new AppUser(3, 'alice'), 'board', 'b1', Images::png(20, 10), 'seed.png');
        $manager    = $this->entityManager($kernel);
        $connection = $manager->getConnection();
        $files      = $manager->getClassMetadata(StoredFile::class)->getTableName();
        $references = $manager->getClassMetadata(StoredFileReference::class)->getTableName();
        // Deterministic values for the columns an upload fills with random or current values.
        $connection->executeStatement('UPDATE ' . $files . ' SET uuid = ?, storage_key = ?, variants = ?, created_at = ?, orphaned_since = ? WHERE uuid = ?', ['00000000-0000-4000-8000-000000000000', '2026/01/0', '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00', $view->uuid]);
        $base = $connection->fetchAssociative('SELECT * FROM ' . $files);
        self::assertIsArray($base);
        unset($base['id']);
        $uploaders = [['alice', 'App:3'], ['alice', null], ['bob', 'App:9'], ['bob', null], ['carol', 'App:3'], ['Alice', null], ['alice', 'App:7']];
        for ($i = 1; $i <= self::ROWS; ++$i) {
            [$identifier, $key] = $uploaders[$i % count($uploaders)];
            $row                = [
                'uuid'            => sprintf('00000000-0000-4000-8000-%012d', $i),
                'uploaded_by'     => $i % 50 === 0 ? null : $identifier,
                'uploaded_by_key' => $key,
                'original_name'   => sprintf('photo-%d.png', $i),
                'storage_key'     => sprintf('2026/01/%d', $i),
                'orphaned_since'  => $i % 4 === 0 ? null : '2026-01-0' . (1 + $i % 9) . ' 00:00:00',
            ] + $base;
            $connection->insert($files, $row);
        }
        $ids = $connection->fetchFirstColumn('SELECT id FROM ' . $files . ' ORDER BY id');
        foreach ($ids as $n => $id) {
            if ($n % 3 === 0) {
                $connection->insert($references, ['file_id' => $id, 'source_type' => 'note', 'source_id' => 'n' . $n, 'created_at' => '2026-01-01 00:00:00']);
            }
        }

        return $connection;
    }

    /**
     * @return list<array<string, scalar|null>> every row, by id, every column (values as strings: the same on every platform)
     */
    private function rows(Connection $connection, string $table): array
    {
        return array_map(static function (array $row): array {
            ksort($row);

            return array_map(static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $row);
        }, $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditOf(StorageKitTestKernel $kernel): array
    {
        return array_map(static fn (StorageAuditEvent $event): array => ['action' => $event->action, 'scope' => $event->scope, 'actor' => $event->actor, 'details' => $event->details], $this->audit($kernel)->events);
    }
}
