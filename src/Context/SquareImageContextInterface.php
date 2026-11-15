<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\SquareImageUploaderInterface;

/**
 * A file context that holds square images only (1.6: profile pictures, logos). Its files are written through
 * {@see SquareImageUploaderInterface} only: {@see ImageUploaderInterface::uploadImage()} and the upload endpoint refuse
 * it like an unknown context (`context`, 404), so nobody can put a 10 MiB GIF where a 256 px WebP square is expected.
 * Tag it like any other context (`nowo_storage_kit.file_context`, autoconfigured).
 */
interface SquareImageContextInterface extends FileContextInterface
{
}
