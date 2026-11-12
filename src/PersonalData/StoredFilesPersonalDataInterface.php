<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

/**
 * GDPR port of the stored files (1.5, architecture bar rule 1): the export and the erasure of what a person uploaded,
 * keyed by plain strings (the stable uploader key and the user identifier stored at upload), so a DDD host calls it
 * without a security user object. Implemented by {@see StoredFilesPersonalData} (service alias of this interface).
 *
 * The erasure writes with DBAL statements and never flushes an entity manager: it is safe inside the host's own unit
 * of work and transaction.
 */
interface StoredFilesPersonalDataInterface
{
    /**
     * The export section of a person: files stored with `$userKey` (when given) and files without a key stored under
     * `$userIdentifier`. Each row holds scalars only (uuid, name, mime_type, bytes, width, height, sha256, context,
     * in_use, created_at, url).
     *
     * @return list<array<string, scalar|null>>
     */
    public function export(string $userIdentifier, ?string $userKey = null): array;

    /**
     * Erases a person's link to their files (same match as {@see self::export()}): kept files lose their name and
     * uploader, files no text shows are handed to the next purge. Returns the number of files touched.
     */
    public function erase(string $userIdentifier, ?string $userKey = null): int;
}
