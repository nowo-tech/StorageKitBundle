<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Entity\StoredFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Download paths of stored files (`GET <upload.path_prefix>/files/{uuid}/{variant}`): relative, so a text that keeps
 * them (MilkdownEditor inserts the upload answer's `url`) works behind any host name.
 */
final readonly class FileUrls
{
    /** Route of the download endpoint. */
    public const ROUTE_DOWNLOAD = 'nowo_storage_kit_file';

    /** Route of the upload endpoint. */
    public const ROUTE_UPLOAD = 'nowo_storage_kit_upload';

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The download path of a variant.
     */
    public function download(string $uuid, string $variant = StoredFile::VARIANT_PREVIEW, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->urls->generate(self::ROUTE_DOWNLOAD, ['uuid' => $uuid, 'variant' => $variant], $referenceType);
    }

    /**
     * The upload path of a context.
     */
    public function upload(string $contextType, string $contextId): string
    {
        return $this->urls->generate(self::ROUTE_UPLOAD, ['type' => $contextType, 'id' => $contextId]);
    }
}
