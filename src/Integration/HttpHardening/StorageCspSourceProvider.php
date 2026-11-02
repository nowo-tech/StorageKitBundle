<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Integration\HttpHardening;

use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\StorageKitBundle\Http\StorageCspSources;
use Symfony\Component\HttpFoundation\Request;

/**
 * nowo-tech/http-hardening-bundle adapter (registered when the bundle is installed): adds {@see StorageCspSources} to
 * `img-src`, and nothing when no profile is redirect-delivered.
 */
final readonly class StorageCspSourceProvider implements CspSourceProviderInterface
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageCspSources $sources,
    ) {
    }

    /**
     * `['img-src' => [origins]]` or `[]`.
     */
    public function getSources(Request $request): array
    {
        $origins = $this->sources->imgSrc();

        return $origins === [] ? [] : ['img-src' => $origins];
    }
}
