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

use function count;

/**
 * GDPR hooks for the host's personal-data export and erasure (UserKit's eraser / export provider, or your own), keyed
 * by the user identifier stored at upload:
 *
 * - {@see self::export()}: the files the user uploaded — metadata and the link that downloads the original (the bytes
 *   stay in the bucket; the link answers while the user may still read the context);
 * - {@see self::erase()}: files a kept text still shows lose their name and uploader; files no text shows are handed to
 *   the next purge (bucket objects and row). No bucket call here: erasers usually run inside one transaction.
 *
 * Both walk the files in keyset pages of {@see self::PAGE}.
 */
final readonly class StoredFilesPersonalData
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
    ) {
    }

    /**
     * The export section of a user.
     *
     * @return list<array<string, scalar|null>>
     */
    public function export(string $userIdentifier): array
    {
        $rows = [];
        foreach ($this->pages($userIdentifier) as $page) {
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
     * Erases a user's link to their files; returns the number of files touched.
     */
    public function erase(string $userIdentifier): int
    {
        $longAgo = new DateTimeImmutable('2000-01-01');
        $manager = $this->entityManager->manager();
        $count   = 0;
        foreach ($this->pages($userIdentifier) as $page) {
            foreach ($page as ['file' => $file, 'referenced' => $referenced]) {
                $file->anonymise();
                if (!$referenced) {
                    $file->expireOrphan($longAgo);
                }
            }
            $manager->flush();
            $count += count($page);
        }
        $this->audit->record(StorageAuditEvent::FILES_ERASED, null, null, ['files' => $count]);

        return $count;
    }

    /**
     * @return iterable<list<array{file: StoredFile, referenced: bool}>>
     */
    private function pages(string $userIdentifier): iterable
    {
        $after = 0;
        while (true) {
            $page = $this->files->uploadedBy($userIdentifier, $after, self::PAGE);
            if ($page === []) {
                return;
            }
            yield $page;
            $after = (int) $page[count($page) - 1]['file']->getId();
        }
    }
}
