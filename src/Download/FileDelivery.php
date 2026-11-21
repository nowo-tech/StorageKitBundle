<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use DateTimeImmutable;
use LogicException;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

use function in_array;
use function max;
use function pathinfo;
use function preg_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PATHINFO_FILENAME;

/**
 * Serves an authorised download. A config profile with `delivery: redirect` answers a `302` to a pre-signed URL of
 * its private bucket (lifetime `download.url_ttl`; the bucket sends the stored type, the configured disposition and a
 * private cache). Every other profile — in particular any bucket typed into a page — is streamed by the application:
 * stored type, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, the
 * configured `Content-Disposition` (default `attachment`; `<img>` still renders it), so no CSP host is opened and a
 * hostile object cannot run script on the application origin. Both are private, short-lived and `noindex`.
 *
 * Since 1.5 the result is a framework-free {@see DeliverableFile} ({@see self::deliver()}, the
 * {@see FileDeliveryInterface} port); {@see FileDeliveryResponder} builds the HttpFoundation response (2.0 removed the
 * 1.4 `respond()`, deprecated since 1.5).
 */
final readonly class FileDelivery implements FileDeliveryInterface
{
    /** CSP of a streamed file. */
    public const SANDBOX_CSP = "default-src 'none'; sandbox";

    /** File extension per stored type. */
    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @param int $ttl `download.url_ttl` (seconds)
     * @param string $disposition `download.disposition` (`attachment` or `inline`)
     * @param StoredFileRepository|null $files the files by public id (1.5, needed by {@see self::deliver()})
     */
    public function __construct(
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private int $ttl = 300,
        private string $disposition = HeaderUtils::DISPOSITION_ATTACHMENT,
        private ?StoredFileRepository $files = null,
    ) {
    }

    /**
     * The delivery of a variant of the file `$uuid` (1.5, {@see FileDeliveryInterface}). Access is the caller's job.
     *
     * @throws FileNotDeliverable when there is no such file or variant (an expired file is none, 1.6), or its profile is not connectable any more
     * @throws LogicException when the service was built without the file repository
     */
    public function deliver(string $uuid, string $variant): DeliverableFile
    {
        if (!in_array($variant, StoredFile::VARIANTS, true)) {
            throw new FileNotDeliverable('Unknown file variant.');
        }
        $files = $this->files ?? throw new LogicException('FileDelivery::deliver() needs the StoredFileRepository (constructor argument $files).');
        $file  = $files->findOneByUuid(strtolower($uuid)) ?? throw new FileNotDeliverable('No such file.');
        if ($file->isExpired()) {
            throw new FileNotDeliverable('No such file.'); // 1.6: erased or removed, waiting for the purge
        }

        return $this->prepare($file, $variant);
    }

    /**
     * The delivery of a variant of a file the viewer may read (1.5).
     *
     * @internal entity-typed: call {@see self::deliver()} (the port) from host code
     *
     * @throws FileNotDeliverable when the profile is not connectable any more
     */
    public function prepare(StoredFile $file, string $variant): DeliverableFile
    {
        $object      = $file->variant($variant);
        $settings    = $this->profiles->settingsOf($file->getProfile()) ?? throw new FileNotDeliverable('The bucket of this file is not connectable.');
        $connection  = $this->storage->connection($settings);
        $disposition = $this->disposition($file, $object['mime']);
        $maxAge      = max(0, $this->ttl - 60);
        if ($this->profiles->deliveryOf($file->getProfile()) === ConfigProfiles::DELIVERY_REDIRECT) {
            return DeliverableFile::redirect($connection->presign($object['key'], $object['mime'], $disposition, new DateTimeImmutable('+' . $this->ttl . ' seconds')), $maxAge);
        }
        $key  = $object['key'];
        $size = $object['size'];

        // @igor-ignore - The closure is the body source of this delivery only (kept by the per-request VO).
        return DeliverableFile::stream(static fn (): iterable => $connection->readRange($key, $size), $size, $maxAge, [
            'Content-Length'               => (string) $size,
            'Content-Type'                 => in_array($object['mime'], ImageProcessor::MIME_TYPES, true) ? $object['mime'] : 'application/octet-stream',
            'Content-Disposition'          => $disposition,
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => self::SANDBOX_CSP,
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /**
     * Writes at most `$size` bytes of `$chunks` (audit CJ L2 / L2b: the object is asked for with a byte range of the size
     * stored at upload and sent as it arrives, never buffered whole); a bucket that sends more is cut there, the rest
     * is not read. Returns the bytes written.
     *
     * @param iterable<string> $chunks
     * @param callable(string): void $write
     *
     * @throws RuntimeException when the bucket sent more than the stored size (the rest is not sent)
     */
    public static function copy(iterable $chunks, int $size, callable $write): int
    {
        $sent = 0;
        foreach ($chunks as $chunk) {
            if ($sent + strlen($chunk) > $size) {
                $write(substr($chunk, 0, $size - $sent));

                throw new RuntimeException('The stored object is larger than the size recorded at upload; the download was cut.');
            }
            $sent += strlen($chunk);
            $write($chunk);
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
