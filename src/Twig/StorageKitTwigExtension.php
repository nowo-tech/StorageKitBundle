<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Twig;

use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Download\StoredImage;
use Nowo\StorageKitBundle\Download\StoredImageResolver;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function in_array;

/**
 * Twig functions for editors and renderers:
 *
 * - `nowo_storage_upload(type, id)`: the upload configuration of a context ({@see UploadEndpoint::config()});
 * - `nowo_storage_milkdown_upload(type, id)`: MilkdownEditorBundle upload options (merge into the field options);
 * - `nowo_storage_file_url(uuid, variant = 'preview')`: the download path of a file (the endpoint checks access);
 * - `nowo_storage_images(text)`: the stored images of a text the current user may see, uuid => StoredImage.
 */
final class StorageKitTwigExtension extends AbstractExtension
{
    /**
     * Services behind the functions.
     */
    public function __construct(
        private readonly UploadEndpoint $endpoint,
        private readonly FileUrls $urls,
        private readonly StoredImageResolver $images,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * The functions.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_storage_upload', $this->endpoint->config(...)),
            new TwigFunction('nowo_storage_milkdown_upload', $this->endpoint->milkdownOptions(...)),
            new TwigFunction('nowo_storage_file_url', $this->fileUrl(...)),
            new TwigFunction('nowo_storage_images', $this->images(...)),
        ];
    }

    /**
     * Download path of a variant (unknown variants fall back to the preview).
     */
    public function fileUrl(string $uuid, string $variant = StoredFile::VARIANT_PREVIEW): string
    {
        return $this->urls->download($uuid, in_array($variant, StoredFile::VARIANTS, true) ? $variant : StoredFile::VARIANT_PREVIEW);
    }

    /**
     * Visible images of a text.
     *
     * @return array<string, StoredImage>
     */
    public function images(?string $text): array
    {
        return $text === null || $text === '' ? [] : $this->images->resolveText($text, $this->guard->user());
    }
}
