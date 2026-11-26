<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Entity\StoredFile;

/**
 * Download port for host routes that serve stored files themselves (2.2): ChatKit's signed attachment URLs, a
 * quarantine-aware route. Implemented by {@see FileDelivery} (service alias of this interface), next to
 * {@see FileDeliveryInterface}. It does not check access: call it after your own checks, and answer
 * {@see FileNotDeliverable} like a missing file (the same `404`).
 *
 * - The file is found by its public id **and** its context, both compared byte for byte (a file of another context is
 *   never served by your route, even with a known id). Expired files and variants that were not written are not
 *   served; a variant that was not generated falls back to the original only for images StorageKit re-encoded.
 * - **Range:** a single `bytes=` range is answered `206` with `Content-Range` (media seeking); an unsatisfiable one
 *   `416`; anything else is ignored (`200`, the whole object). Pass null when the request has an `If-Range` header.
 *   Redirect-delivered profiles answer `302` to a pre-signed URL (the bucket serves ranges itself).
 * - **Disposition:** `attachment` unless `$inline` is true (or null and `download.disposition` is `inline`) **and**
 *   the object may be shown inline: a raster image StorageKit checked (a variant, or an image upload's original) or an
 *   audio / video type ({@see MediaTypes::inlineSafe()}). Active content (HTML, SVG, XML, script) is always an
 *   `attachment` of type `application/octet-stream`. Every stream carries `nosniff` and the sandbox CSP.
 */
interface FileRangeDeliveryInterface
{
    /**
     * The delivery of variant `$variant` of the file `$uuid` stored in context `$contextType` / `$contextId`.
     *
     * @param string $variant {@see StoredFile::VARIANT_ORIGINAL}, {@see StoredFile::VARIANT_PREVIEW} or {@see StoredFile::VARIANT_THUMB}
     * @param string|null $range the request's `Range` header (null: none, or an `If-Range` was sent)
     * @param bool|null $inline true = inline when safe, false = attachment, null = `download.disposition` when safe
     * @param int|null $ttl lifetime of a pre-signed URL and of the private cache, in seconds (null or larger: `download.url_ttl`)
     *
     * @throws FileNotDeliverable when there is no such file, variant or context ({@see FileContextInterface} ids), or its bucket is not connectable
     */
    public function deliverInContext(string $uuid, string $contextType, string $contextId, string $variant, ?string $range = null, ?bool $inline = null, ?int $ttl = null): DeliverableFile;
}
