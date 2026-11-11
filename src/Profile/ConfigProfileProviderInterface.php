<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Profile;

use Nowo\StorageKitBundle\Storage\S3Settings;

/**
 * Port (1.4, architecture bar rule 8): the named buckets managed by operations — the source of "config profiles" and
 * of a config-pinned instance bucket (`instance_profile`). The default is {@see ConfigProfiles} (the
 * `nowo_storage_kit.profiles` tree); a host aliases `nowo_storage_kit.config_profile_provider` to its own service to
 * read them from a secret manager, its own tenant configuration or anything else — no Instance Settings needed.
 *
 * Contract:
 * - A name, once used, must keep meaning the same bucket: files point at a profile row by name.
 * - Methods are called per use (every upload, download and page): keep no request, user or tenant state in the
 *   implementation (FrankenPHP worker mode, kernel kept alive). Rotated credentials take effect on the next call.
 * - Profiles from this port are trusted configuration: not checked by the SSRF guard, and a profile delivered by
 *   redirect adds its origin to the CSP `img-src`.
 */
interface ConfigProfileProviderInterface
{
    /**
     * Profile names (also listed on the Move page as targets).
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * Whether a profile with this name exists.
     */
    public function has(string $name): bool;

    /**
     * {@see ConfigProfiles::DELIVERY_REDIRECT} (pre-signed URLs) or {@see ConfigProfiles::DELIVERY_PROXY} (streamed by
     * the application). Anything else counts as proxy.
     */
    public function delivery(string $name): string;

    /**
     * The connection settings of a profile, or null when it is unknown or not usable (uploads to it are off).
     */
    public function settings(string $name): ?S3Settings;
}
