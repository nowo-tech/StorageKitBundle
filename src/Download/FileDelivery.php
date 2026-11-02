<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function in_array;
use function max;
use function pathinfo;
use function preg_replace;
use function trim;

use const PATHINFO_FILENAME;

/**
 * Serves an authorised download. A config profile with `delivery: redirect` answers a `302` to a pre-signed URL of
 * its private bucket (lifetime `download.url_ttl`; the bucket sends the stored type, the configured disposition and a
 * private cache). Every other profile — in particular any bucket typed into a page — is streamed by the application:
 * stored type, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, the
 * configured `Content-Disposition` (default `attachment`; `<img>` still renders it), so no CSP host is opened and a
 * hostile object cannot run script on the application origin. Both are private, short-lived and `noindex`.
 */
final readonly class FileDelivery
{
    /** CSP of a streamed file. */
    public const SANDBOX_CSP = "default-src 'none'; sandbox";

    /** File extension per stored type. */
    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @param int $ttl `download.url_ttl` (seconds)
     * @param string $disposition `download.disposition` (`attachment` or `inline`)
     */
    public function __construct(
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private int $ttl = 300,
        private string $disposition = HeaderUtils::DISPOSITION_ATTACHMENT,
    ) {
    }

    /**
     * The response of a variant of a file the viewer may read.
     *
     * @throws NotFoundHttpException when the profile is not connectable any more
     */
    public function respond(StoredFile $file, string $variant): Response
    {
        $object      = $file->variant($variant);
        $settings    = $this->profiles->settingsOf($file->getProfile()) ?? throw new NotFoundHttpException();
        $connection  = $this->storage->connection($settings);
        $disposition = $this->disposition($file, $object['mime']);
        if ($this->profiles->deliveryOf($file->getProfile()) === ConfigProfiles::DELIVERY_REDIRECT) {
            $response = new RedirectResponse($connection->presign($object['key'], $object['mime'], $disposition, new DateTimeImmutable('+' . $this->ttl . ' seconds')), Response::HTTP_FOUND);
        } else {
            $response = new Response($connection->read($object['key']), Response::HTTP_OK, [
                'Content-Type'                 => in_array($object['mime'], ImageProcessor::MIME_TYPES, true) ? $object['mime'] : 'application/octet-stream',
                'Content-Disposition'          => $disposition,
                'X-Content-Type-Options'       => 'nosniff',
                'Content-Security-Policy'      => self::SANDBOX_CSP,
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ]);
        }
        $response->setPrivate();
        $response->setMaxAge(max(0, $this->ttl - 60));
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function disposition(StoredFile $file, string $mime): string
    {
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $base      = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getOriginalName(), PATHINFO_FILENAME)), '._');
        $ascii     = ($base === '' ? StoredFile::ANONYMOUS_NAME : $base) . '.' . $extension;

        return HeaderUtils::makeDisposition($this->disposition, $ascii);
    }
}
