<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function floor;
use function in_array;
use function mb_substr;
use function min;

/**
 * A copy of every file of one storage profile into another (a provider change, a region move, an exit) — started from
 * the Move page (Messenger) or by `nowo:storage-kit:sync`. The state is saved after every file (`cursor` = the last
 * file id copied and verified), so a stopped move resumes where it was and never copies a file twice; an object
 * already in the target with the same SHA-256 is not written again (idempotent). The first failure stops it
 * (`failed`, with the file and a generic reason); *Resume* retries from that file. *Switch* needs a `completed` sync.
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_kit_sync')]
#[ORM\UniqueConstraint(name: 'uniq_storage_kit_sync_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_storage_kit_sync_pair', columns: ['from_id', 'to_id'])]
class StorageSync
{
    /** Waiting for a runner. */
    public const QUEUED = 'queued';

    /** A runner holds it. */
    public const RUNNING = 'running';

    /** Every file copied and verified. */
    public const COMPLETED = 'completed';

    /** Stopped at a file. */
    public const FAILED = 'failed';

    /** Stopped by a user. */
    public const CANCELLED = 'cancelled';

    /** Longest stored error. */
    public const ERROR_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column(length: 16)]
    private string $status = self::QUEUED;

    /** The last file id copied and verified (files are taken in id order). */
    #[ORM\Column(name: 'last_file_id')]
    private int $cursor = 0;

    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column]
    private int $processed = 0;

    /** Objects written to the target (an object already there with the same checksum is not written again). */
    #[ORM\Column(name: 'copied_objects')]
    private int $copiedObjects = 0;

    #[ORM\Column(name: 'skipped_objects')]
    private int $skippedObjects = 0;

    #[ORM\Column(length: self::ERROR_MAX, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'started_by', length: 180, nullable: true)]
    private ?string $startedBy = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /**
     * A sync from one profile to another.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: StorageProfile::class)]
        #[ORM\JoinColumn(name: 'from_id', nullable: false, onDelete: 'CASCADE')]
        private StorageProfile $from,
        #[ORM\ManyToOne(targetEntity: StorageProfile::class)]
        #[ORM\JoinColumn(name: 'to_id', nullable: false, onDelete: 'CASCADE')]
        private StorageProfile $to,
    ) {
        $this->uuid      = Uuid::v4()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Database id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Public id.
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Source profile.
     */
    public function getFrom(): StorageProfile
    {
        return $this->from;
    }

    /**
     * Target profile.
     */
    public function getTo(): StorageProfile
    {
        return $this->to;
    }

    /**
     * Status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Queued or running: not finished, may be resumed.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /**
     * Every file copied and verified.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * Last file id copied and verified.
     */
    public function getCursor(): int
    {
        return $this->cursor;
    }

    /**
     * Files of the run.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Files done.
     */
    public function getProcessed(): int
    {
        return $this->processed;
    }

    /**
     * Progress, 0–100.
     */
    public function percent(): int
    {
        return $this->total === 0 ? ($this->isCompleted() ? 100 : 0) : (int) min(100, floor($this->processed * 100 / $this->total));
    }

    /**
     * Objects written.
     */
    public function getCopiedObjects(): int
    {
        return $this->copiedObjects;
    }

    /**
     * Objects already in the target.
     */
    public function getSkippedObjects(): int
    {
        return $this->skippedObjects;
    }

    /**
     * Generic failure reason (`<file uuid>: <reason>`), never a provider message.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * User identifier of whoever started it.
     */
    public function getStartedBy(): ?string
    {
        return $this->startedBy;
    }

    /**
     * Sets who started it.
     */
    public function setStartedBy(?string $userIdentifier): void
    {
        $this->startedBy = $userIdentifier;
    }

    /**
     * Creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Last progress.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * End of the last run.
     */
    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * Waiting for a runner (a new sync, a resumed one, or one that paused at the end of its time slice).
     */
    public function queue(): void
    {
        $this->status     = self::QUEUED;
        $this->error      = null;
        $this->finishedAt = null;
        $this->touch();
    }

    /**
     * (Re)starts: running, the error cleared; `total` = the files done so far plus the ones still to copy.
     */
    public function start(int $remaining): void
    {
        $this->status     = self::RUNNING;
        $this->error      = null;
        $this->finishedAt = null;
        $this->total      = $this->processed + $remaining;
        $this->touch();
    }

    /**
     * One file copied (or found already there) and verified.
     */
    public function advance(int $fileId, int $copied, int $skipped): void
    {
        $this->cursor = $fileId;
        ++$this->processed;
        $this->copiedObjects += $copied;
        $this->skippedObjects += $skipped;
        $this->touch();
    }

    /**
     * Done.
     */
    public function complete(): void
    {
        $this->status     = self::COMPLETED;
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    /**
     * Stopped at a file.
     */
    public function fail(string $error): void
    {
        $this->status     = self::FAILED;
        $this->error      = mb_substr($error, 0, self::ERROR_MAX);
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    /**
     * Stopped by a user.
     */
    public function cancel(): void
    {
        $this->status     = self::CANCELLED;
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
