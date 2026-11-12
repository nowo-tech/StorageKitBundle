<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

/**
 * Download port (1.5, architecture bar rule 3): a variant of a stored file, by its public id, as a framework-free
 * {@see DeliverableFile}. Implemented by {@see FileDelivery} (service alias of this interface); the download
 * controller calls this port and turns the result into a response with {@see FileDeliveryResponder}. Alias the
 * interface to your own service to change how files are served (it is then called instead of the bundle's).
 *
 * It does not check access: call it only after the viewer was allowed to read the file's context (the download
 * controller does), and answer {@see FileNotDeliverable} like a missing file.
 */
interface FileDeliveryInterface
{
    /**
     * The delivery of variant `$variant` (`original`, `preview` or `thumb`) of the file `$uuid`.
     *
     * @throws FileNotDeliverable when there is no such file or variant, or its bucket is not connectable any more
     */
    public function deliver(string $uuid, string $variant): DeliverableFile;
}
