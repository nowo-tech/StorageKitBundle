<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;

/**
 * GDPR hooks for the host's personal-data export and erasure (UserKit's eraser / export provider, or your own), keyed
 * by the stable uploader key ({@see UploaderKeyResolverInterface}, the user id by default) and by the user identifier
 * stored at upload for files that have no key:
 *
 * - {@see self::export()}: the files the user uploaded — metadata and the link that downloads the original (the bytes
 *   stay in the bucket; the link answers while the user may still read the context);
 * - {@see self::erase()}: files a kept text still shows lose their name and uploader; files no text shows are handed to
 *   the next purge (bucket objects and row). No bucket call here: erasers usually run inside one transaction.
 *
 * Pass the user ({@see self::exportUser()}, {@see self::eraseUser()}) or both values ({@see self::export()},
 * {@see self::erase()} with `$userKey`): a file stored with the key is found after the user identifier (often an
 * e-mail) changed. Called with the identifier only, they match the identifier only, as before 1.2.0.
 *
 * Both walk the files in keyset pages of {@see self::PAGE}. Type-hint {@see StoredFilesPersonalDataInterface} (1.5) to
 * depend on the port only (strings in, scalars out).
 */
final readonly class StoredFilesPersonalData implements StoredFilesPersonalDataInterface
{
    /** Files per page. */
    public const PAGE = 500;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StoredFileRepository $files,
        private StorageEntityManager $entityManager,
        private FileUrls $urls,
        private AuditRecorder $audit,
        private UploaderKeyResolverInterface $uploaderKeys = new UserIdUploaderKeyResolver(),
    ) {
    }

    /**
     * The export section of a security user: their stable key and their current user identifier.
     *
     * @return list<array<string, scalar|null>>
     */
    public function exportUser(UserInterface $user): array
    {
        return $this->export($user->getUserIdentifier(), $this->uploaderKeys->keyOf($user));
    }

    /**
     * Erases a security user's link to their files (stable key and current user identifier); returns the files touched.
     */
    public function eraseUser(UserInterface $user): int
    {
        return $this->erase($user->getUserIdentifier(), $this->uploaderKeys->keyOf($user));
    }

    /**
     * The export section of a user: files stored with `$userKey` (when given) and files without a key stored under
     * `$userIdentifier`.
     *
     * @return list<array<string, scalar|null>>
     */
    public function export(string $userIdentifier, ?string $userKey = null): array
    {
        $rows = [];
        foreach ($this->pages($userIdentifier, $userKey) as $page) {
            foreach ($page as ['file' => $file, 'referenced' => $referenced]) {
                $rows[] = [
                    'uuid'       => $file->getUuid(),
                    'name'       => $file->getOriginalName(),
                    'mime_type'  => $file->getMimeType(),
                    'bytes'      => $file->getSize(),
                    'width'      => $file->getWidth(),
                    'height'     => $file->getHeight(),
                    'sha256'     => $file->getSha256(),
                    'context'    => $file->getContextType() . '/' . $file->getContextId(),
                    'in_use'     => $referenced,
                    'created_at' => $file->getCreatedAt()->format(DateTimeInterface::ATOM),
                    'url'        => $this->urls->download($file->getUuid(), StoredFile::VARIANT_ORIGINAL, UrlGeneratorInterface::ABSOLUTE_URL),
                ];
            }
        }

        return $rows;
    }

    /**
     * Erases a user's link to their files (same match as {@see self::export()}); returns the number of files touched.
     *
     * Since 1.5 (architecture bar rule 6) the rows are changed with DBAL `UPDATE` statements, page by page: no entity
     * manager is flushed, so nothing the application left pending in it is written (an eraser may run in the middle of
     * the host's own unit of work). Each page is one unit: a transaction of its own, or a savepoint inside the host's
     * transaction (a failure rolls back that page only and leaves the host's transaction usable). Files the entity
     * manager already holds show the erased values too.
     */
    public function erase(string $userIdentifier, ?string $userKey = null): int
    {
        $longAgo = new DateTimeImmutable('2000-01-01');
        $count   = 0;
        $after   = 0;
        while (true) {
            $page = $this->files->uploadedIdsBy($userIdentifier, $after, self::PAGE, $userKey);
            if ($page === []) {
                break;
            }
            $ids     = [];
            $orphans = [];
            foreach ($page as ['id' => $id, 'referenced' => $referenced]) {
                $ids[] = $id;
                if (!$referenced) {
                    $orphans[] = $id;
                }
            }
            // One unit per page (audit NH-2): a file is never anonymised without being handed to the purge.
            $writes = $this->entityManager;
            // @igor-ignore - The closure runs synchronously inside this call (one erase page); it is not stored.
            $writes->atomically(static function () use ($writes, $ids, $orphans, $longAgo): void {
                $writes->updateRows(StoredFile::class, $ids, ['originalName' => StoredFile::ANONYMOUS_NAME, 'uploadedBy' => null, 'uploadedByKey' => null]);
                $writes->updateRows(StoredFile::class, $orphans, ['orphanedSince' => $longAgo]);
            }, 'erase');
            $count += count($page);
            $after = $page[count($page) - 1]['id'];
        }
        $this->audit->record(StorageAuditEvent::FILES_ERASED, null, null, ['files' => $count]);

        return $count;
    }

    /**
     * @return iterable<list<array{file: StoredFile, referenced: bool}>>
     */
    private function pages(string $userIdentifier, ?string $userKey): iterable
    {
        $after = 0;
        while (true) {
            $page = $this->files->uploadedBy($userIdentifier, $after, self::PAGE, $userKey);
            if ($page === []) {
                return;
            }
            yield $page;
            $after = (int) $page[count($page) - 1]['file']->getId();
        }
    }
}
