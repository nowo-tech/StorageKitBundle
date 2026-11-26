<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

use Nowo\StorageKitBundle\Download\MediaTypes;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;

/**
 * A file context that takes generic (non-image) files (2.2): its own allow-list of media types and its own size limit.
 * Only a context that implements it accepts {@see FileUploaderInterface::uploadFile()}; any other context refuses
 * generic files like an unknown one (`context`, deny by default). Tag it like any other context
 * (`nowo_storage_kit.file_context`, autoconfigured).
 *
 * The answers are compared exactly: a declared type is accepted only when it is `===` one of {@see self::acceptedTypes()}
 * (no case folding, no parameters). Image uploads into such a context ({@see ImageUploaderInterface::uploadImage()})
 * also need their sniffed type in the list, and the smaller of {@see self::maxBytes()} and `upload.max_bytes` applies.
 *
 * Who may read the files is still {@see FileContextInterface::readable()}: a context whose files must only be served
 * by the host's own route (quarantine, signed URLs…) answers `[]` there, and StorageKit's download route then answers
 * 404 for all of them.
 */
interface GenericFileContextInterface extends FileContextInterface
{
    /**
     * The media types this context stores, as bare lower-case `type/subtype` values ({@see MediaTypes::isValid()};
     * anything else in the list never matches).
     *
     * @return list<string>
     */
    public function acceptedTypes(): array;

    /**
     * The largest file this context stores, in bytes (the bundle's ceiling `upload.files.max_bytes` caps it; 0 or less
     * refuses every file).
     */
    public function maxBytes(): int;
}
