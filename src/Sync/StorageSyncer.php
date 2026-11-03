<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Sync;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Storage\ErrorScrubber;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

use function array_keys;
use function array_slice;
use function count;
use function hash;
use function hash_equals;
use function is_array;
use function is_string;
use function json_decode;
use function sort;
use function time;

/**
 * Copies every file of one profile's bucket to another's — the original and its variants, in file id order, one file
 * at a time with the state saved after each ({@see StorageSync}): a stopped move resumes after the last verified file
 * (resumable), and an object already in the target with the same SHA-256 is not written again (idempotent). Every
 * copy is read back and compared (the original also against the checksum stored at upload). The first failure stops
 * the sync with the file and a generic reason (the provider's message, scrubbed, goes to the log only).
 *
 * One runner at a time per sync: a {@see LockFactory} lock on the sync (`lock.factory`, refreshed after every file)
 * and the atomic database claim of {@see self::claim()} (queued → running; a running sync not updated for
 * `sync.stale_seconds` is taken over: its worker died).
 */
final readonly class StorageSyncer
{
    /** Default `sync.batch_size`. */
    public const BATCH = 50;

    /** Default `sync.stale_seconds`. */
    public const STALE_SECONDS = 120;

    /** Keys listed by {@see self::check()} per category at most. */
    public const CHECK_LISTED = 100;

    /** Objects listed from the target bucket by {@see self::check()} at most. */
    public const CHECK_SCAN_LIMIT = 100_000;

    private LockFactory $locks;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageSyncRepository $syncs,
        private StorageEntityManager $entityManager,
        private StorageProfiles $profiles,
        private FileStorage $storage,
        ?LockFactory $locks = null,
        private int $batch = self::BATCH,
        private int $staleSeconds = self::STALE_SECONDS,
        private LoggerInterface $logger = new NullLogger(),
        private int $checkScanLimit = self::CHECK_SCAN_LIMIT,
    ) {
        $this->locks = $locks ?? new LockFactory(new InMemoryStore());
    }

    /**
     * The sync of a pair, queued (a new one, or the last one reopened: it resumes after its last verified file).
     *
     * @throws InvalidArgumentException when both profiles are the same
     */
    public function open(StorageProfile $from, StorageProfile $to): StorageSync
    {
        if ($from === $to || ($from->getId() !== null && $from->getId() === $to->getId())) {
            throw new InvalidArgumentException('A profile cannot be synced to itself.');
        }
        $manager = $this->entityManager->manager();
        $sync    = $this->syncs->forPair($from, $to);
        if (!$sync instanceof StorageSync) {
            $sync = new StorageSync($from, $to);
            $manager->persist($sync);
        } elseif ($sync->getStatus() !== StorageSync::RUNNING) {
            $sync->queue();
        }
        $manager->flush();

        return $sync;
    }

    /**
     * Runs a queued sync until it is done, fails, is cancelled or `$seconds` pass (then it is queued again: the caller
     * dispatches the next slice). False when another runner holds it.
     *
     * @param (callable(StorageSync): void)|null $progress called after every file
     */
    public function run(StorageSync $sync, ?int $seconds = null, ?callable $progress = null): bool
    {
        $lock = $this->locks->createLock('nowo_storage_kit/sync/' . $sync->getId(), (float) $this->staleSeconds);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            return $this->runLocked($sync, $lock, $seconds, $progress);
        } finally {
            $lock->release();
        }
    }

    /**
     * Stops an open sync.
     */
    public function cancel(StorageSync $sync): void
    {
        if ($sync->isOpen()) {
            $sync->cancel();
            $this->entityManager->manager()->flush();
        }
    }

    /**
     * Compares the database with a bucket: the objects of the source's files missing from the target bucket, and the
     * objects under the target's prefix that belong to no file of either profile (`$from === $to` checks one bucket
     * against its rows). Lists are cut at {@see self::CHECK_LISTED}; the scan stops after `$checkScanLimit` objects
     * (default {@see self::CHECK_SCAN_LIMIT}).
     *
     * @return array{expected: int, missing: list<string>, missing_count: int, unknown: list<string>, unknown_count: int, truncated: bool}
     */
    public function check(StorageProfile $from, StorageProfile $to): array
    {
        $expected = $this->keysOf($from);
        $known    = $from === $to ? $expected : $expected + $this->keysOf($to);
        $present  = [];
        $unknown  = [];
        $scanned  = 0;
        foreach ($this->connect($to)->keys($this->checkScanLimit + 1) as $key) {
            ++$scanned;
            if ($scanned > $this->checkScanLimit) {
                break;
            }
            $present[$key] = true;
            if (!isset($known[$key])) {
                $unknown[] = $key;
            }
        }
        $missing = [];
        foreach (array_keys($expected) as $key) {
            if (!isset($present[$key])) {
                $missing[] = (string) $key;
            }
        }
        sort($missing);
        sort($unknown);

        return [
            'expected'      => count($expected),
            'missing'       => array_slice($missing, 0, self::CHECK_LISTED),
            'missing_count' => count($missing),
            'unknown'       => array_slice($unknown, 0, self::CHECK_LISTED),
            'unknown_count' => count($unknown),
            'truncated'     => $scanned > $this->checkScanLimit,
        ];
    }

    /**
     * Files of the source written after the sync's last verified file (uploads during the sync).
     */
    public function remaining(StorageSync $sync): int
    {
        return (int) $this->entityManager->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->table(StoredFile::class) . ' WHERE profile_id = ? AND id > ?',
            [$sync->getFrom()->getId(), $sync->getCursor()],
        );
    }

    /**
     * @param (callable(StorageSync): void)|null $progress
     */
    private function runLocked(StorageSync $sync, LockInterface $lock, ?int $seconds, ?callable $progress): bool
    {
        if (!$this->claim($sync)) {
            return false;
        }
        $manager = $this->entityManager->manager();
        $manager->refresh($sync);
        try {
            $from = $this->connect($sync->getFrom());
            $to   = $this->connect($sync->getTo());
        } catch (RuntimeException $e) {
            $sync->fail($e->getMessage());
            $manager->flush();

            return true;
        }
        $sync->start($this->remaining($sync));
        $manager->flush();
        $deadline   = $seconds === null ? null : time() + $seconds;
        $syncs      = $this->entityManager->table(StorageSync::class);
        $connection = $manager->getConnection();
        while (true) {
            if ($connection->fetchOne('SELECT status FROM ' . $syncs . ' WHERE id = ?', [$sync->getId()]) === StorageSync::CANCELLED) {
                $manager->refresh($sync);

                return true;
            }
            $files = $this->nextBatch($sync);
            if ($files === []) {
                $sync->complete();
                $manager->flush();

                return true;
            }
            foreach ($files as $file) {
                try {
                    [$copied, $skipped] = $this->copyFile($file, $from, $to);
                } catch (Throwable $e) {
                    [$reason, $status] = ErrorScrubber::reason($e);
                    $this->logger->warning('Storage move {sync} stopped at file {file}: {error}', ['sync' => $sync->getUuid(), 'file' => $file['uuid'], 'error' => ErrorScrubber::message($e, $from->settings(), $to->settings())]);
                    $sync->fail($file['uuid'] . ': ' . ($e instanceof SyncFailure ? $e->getMessage() : $reason . ($status === null ? '' : ' ' . $status)));
                    $manager->flush();

                    return true;
                }
                $sync->advance((int) $file['id'], $copied, $skipped);
                $manager->flush();
                $lock->refresh();
                if ($progress !== null) {
                    $progress($sync);
                }
            }
            if ($deadline !== null && time() >= $deadline) {
                $sync->queue();
                $manager->flush();

                return true;
            }
        }
    }

    /**
     * Takes the sync: queued, or running but left by a dead runner.
     */
    private function claim(StorageSync $sync): bool
    {
        return (int) $this->entityManager->connection()->executeStatement(
            'UPDATE ' . $this->entityManager->table(StorageSync::class) . ' SET status = ?, updated_at = ? WHERE id = ? AND (status = ? OR (status = ? AND updated_at < ?))',
            [StorageSync::RUNNING, new DateTimeImmutable(), $sync->getId(), StorageSync::QUEUED, StorageSync::RUNNING, new DateTimeImmutable('-' . $this->staleSeconds . ' seconds')],
            [Types::STRING, Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
        ) === 1;
    }

    /**
     * @return list<array{id: int|string, uuid: string, storage_key: string, mime_type: string, sha256: string, variants: string}>
     */
    private function nextBatch(StorageSync $sync): array
    {
        /** @var list<array{id: int|string, uuid: string, storage_key: string, mime_type: string, sha256: string, variants: string}> $rows */
        $rows = $this->entityManager->connection()->fetchAllAssociative(
            'SELECT id, uuid, storage_key, mime_type, sha256, variants FROM ' . $this->entityManager->table(StoredFile::class) . ' WHERE profile_id = ? AND id > ? ORDER BY id LIMIT ' . $this->batch,
            [$sync->getFrom()->getId(), $sync->getCursor()],
        );

        return $rows;
    }

    /**
     * @param array{id: int|string, uuid: string, storage_key: string, mime_type: string, sha256: string, variants: string} $file
     *
     * @return array{0: int, 1: int} objects copied, objects already there
     */
    private function copyFile(array $file, StorageConnection $from, StorageConnection $to): array
    {
        $objects  = [[$file['storage_key'], $file['mime_type'], $file['sha256']]];
        $variants = json_decode($file['variants'], true);
        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (is_array($variant) && is_string($variant['key'] ?? null) && is_string($variant['mime'] ?? null)) {
                $objects[] = [$variant['key'], $variant['mime'], null];
            }
        }
        $copied  = 0;
        $skipped = 0;
        foreach ($objects as [$key, $mime, $checksum]) {
            if ($this->copyObject($key, $mime, $checksum, $from, $to)) {
                ++$copied;
            } else {
                ++$skipped;
            }
        }

        return [$copied, $skipped];
    }

    /**
     * True = written, false = already there with the same contents.
     */
    private function copyObject(string $key, string $mime, ?string $checksum, StorageConnection $from, StorageConnection $to): bool
    {
        $bytes = $from->read($key);
        $hash  = hash('sha256', $bytes);
        if ($checksum !== null && !hash_equals($checksum, $hash)) {
            throw new SyncFailure('source checksum mismatch');
        }
        if ($to->exists($key) && hash_equals($hash, hash('sha256', $to->read($key)))) {
            return false;
        }
        $to->write($key, $bytes, $mime);
        if (!hash_equals($hash, hash('sha256', $to->read($key)))) {
            throw new SyncFailure('copy checksum mismatch');
        }

        return true;
    }

    /**
     * @throws SyncFailure when the profile is not connectable
     */
    private function connect(StorageProfile $profile): StorageConnection
    {
        $settings = $this->profiles->settingsOf($profile) ?? throw new SyncFailure('profile ' . $profile->getUuid() . ' is not usable');

        return $this->storage->connection($settings);
    }

    /**
     * Every object key of a profile's files (bounded by the table; used by the check only).
     *
     * @return array<string, true>
     */
    private function keysOf(StorageProfile $profile): array
    {
        $keys = [];
        foreach ($this->entityManager->connection()->iterateAssociative('SELECT storage_key, variants FROM ' . $this->entityManager->table(StoredFile::class) . ' WHERE profile_id = ?', [$profile->getId()]) as $row) {
            $keys[(string) $row['storage_key']] = true;
            $variants                           = json_decode((string) $row['variants'], true);
            foreach (is_array($variants) ? $variants : [] as $variant) {
                if (is_array($variant) && is_string($variant['key'] ?? null)) {
                    $keys[$variant['key']] = true;
                }
            }
        }

        return $keys;
    }
}
