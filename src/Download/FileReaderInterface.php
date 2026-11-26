<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Entity\StoredFile;

/**
 * Server-side read port (2.2): a bounded read stream of a stored object for a virus scanner or a preview builder.
 * Aliased to {@see FileReader}. It does not check who reads: it is for server code that already decided (a Messenger
 * handler that scans the host's own file); never pass it a viewer's input unchecked.
 *
 * The file is found by its public id **and** its context, both compared byte for byte; an unknown, expired or foreign
 * file, or a variant that was not written, is {@see FileNotDeliverable}. Close the stream in a `finally`.
 */
interface FileReaderInterface
{
    /**
     * Opens variant `$variant` of the file for reading, at most `$length` bytes from its start (null = all of it).
     *
     * @param string $variant {@see StoredFile::VARIANT_ORIGINAL}, {@see StoredFile::VARIANT_PREVIEW} or {@see StoredFile::VARIANT_THUMB}
     * @param int|null $length read at most this many bytes (a text preview reads its first 64 KiB); null = the whole object
     *
     * @throws FileNotDeliverable when there is no such file, variant or context, or its bucket is not connectable
     */
    public function open(string $uuid, string $contextType, string $contextId, string $variant, ?int $length = null): FileStream;
}
