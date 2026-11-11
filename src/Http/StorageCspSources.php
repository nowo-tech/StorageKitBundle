<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Http;

use Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Storage\S3Settings;

use function array_unique;
use function array_values;

/**
 * The CSP `img-src` origins downloads may be redirected to: only the browser-facing origin of the config profiles with
 * `delivery: redirect` (ops configuration). A bucket typed into a page is never listed — its files are streamed by
 * the application — so no page user can widen the policy; a profile whose settings are not usable adds nothing, and
 * with no such profile the policy is left as it is. Origins come from validated settings (`scheme://host[:port]`).
 */
final readonly class StorageCspSources
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private ConfigProfileProviderInterface $profiles,
    ) {
    }

    /**
     * The origins to add to `img-src`.
     *
     * @return list<string>
     */
    public function imgSrc(): array
    {
        $origins = [];
        foreach ($this->profiles->names() as $name) {
            if ($this->profiles->delivery($name) !== ConfigProfiles::DELIVERY_REDIRECT) {
                continue;
            }
            $settings = $this->profiles->settings($name);
            if ($settings instanceof S3Settings) {
                $origins[] = $settings->browserOrigin();
            }
        }

        return array_values(array_unique($origins));
    }
}
