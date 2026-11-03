<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function fclose;
use function flush;
use function fread;
use function in_array;
use function max;
use function min;
use function pathinfo;
use function preg_replace;
use function strlen;
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
    /** Bytes read from the bucket and sent per step. */
    public const CHUNK = 65536;

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
            $stream = $connection->readStream($object['key']);
            $size   = $object['size'];
            // @igor-ignore - The closure is the response body callback of this request only.
            $response = new StreamedResponse(static function () use ($stream, $size): void {
                self::copy($stream, $size, static function (string $chunk): void {
                    echo $chunk;
                    flush();
                });
            }, Response::HTTP_OK, [
                'Content-Length'               => (string) $size,
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

    /**
     * Copies at most `$size` bytes of `$stream` to `$write` in {@see self::CHUNK} chunks (audit CJ L2: never the whole
     * object in memory); an object longer than the size stored at upload is cut there and logged as an error by the
     * caller's stream (the response length is the stored size). Returns the bytes written.
     *
     * @param resource $stream
     * @param callable(string): void $write
     *
     * @throws RuntimeException when the object is longer than the stored size (the rest is not sent)
     */
    public static function copy($stream, int $size, callable $write): int
    {
        $sent = 0;
        try {
            while ($sent < $size && ($chunk = fread($stream, max(1, min(self::CHUNK, $size - $sent)))) !== false && $chunk !== '') {
                $sent += strlen($chunk);
                $write($chunk);
            }
            if ($sent >= $size && fread($stream, 1) !== '') {
                throw new RuntimeException('The stored object is larger than the size recorded at upload; the download was cut.');
            }
        } finally {
            fclose($stream);
        }

        return $sent;
    }

    private function disposition(StoredFile $file, string $mime): string
    {
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $base      = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getOriginalName(), PATHINFO_FILENAME)), '._');
        $ascii     = ($base === '' ? StoredFile::ANONYMOUS_NAME : $base) . '.' . $extension;

        return HeaderUtils::makeDisposition($this->disposition, $ascii);
    }
}
