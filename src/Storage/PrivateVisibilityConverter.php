<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use League\Flysystem\AsyncAwsS3\VisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Every object stays private. The async-aws adapter always sends a canned ACL; `private` is refused by AWS buckets whose
 * ACLs are disabled (the default since 2023), `bucket-owner-full-control` is accepted by both kinds and keeps the
 * object readable by the bucket owner only. Visibility is never public.
 */
final class PrivateVisibilityConverter implements VisibilityConverter
{
    /** Canned ACL sent with every write. */
    public const ACL = 'bucket-owner-full-control';

    /**
     * Always {@see self::ACL}, whatever was asked.
     */
    public function visibilityToAcl(string $visibility): string
    {
        return self::ACL;
    }

    /**
     * Always private.
     *
     * @param array<mixed> $grants
     */
    public function aclToVisibility(array $grants): string
    {
        return Visibility::PRIVATE;
    }

    /**
     * Always private.
     */
    public function defaultForDirectories(): string
    {
        return Visibility::PRIVATE;
    }
}
