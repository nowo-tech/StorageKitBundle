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
use function min;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PATHINFO_EXTENSION;
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
final readonly class FileDelivery implements FileDeliveryInterface, FileRangeDeliveryInterface
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
     * 2.2 ({@see FileRangeDeliveryInterface}): a variant of the file `$uuid` stored in exactly that context, with
     * `Range` and the disposition choice. Access is the caller's job.
     *
     * @throws FileNotDeliverable when there is no such file, variant or context, or its profile is not connectable any more
     * @throws LogicException when the service was built without the file repository
     */
    public function deliverInContext(string $uuid, string $contextType, string $contextId, string $variant, ?string $range = null, ?bool $inline = null, ?int $ttl = null): DeliverableFile
    {
        if (!in_array($variant, StoredFile::VARIANTS, true)) {
            throw new FileNotDeliverable('Unknown file variant.');
        }
        $files = $this->files ?? throw new LogicException('FileDelivery::deliverInContext() needs the StoredFileRepository (constructor argument $files).');
        $file  = $files->findInContext($uuid, $contextType, $contextId) ?? throw new FileNotDeliverable('No such file.');

        return $this->prepare($file, $variant, $range, $inline, $ttl);
    }

    /**
     * The delivery of a variant of a file the viewer may read (1.5; 2.2: `Range`, disposition and lifetime).
     *
     * @internal entity-typed: call {@see self::deliver()} or {@see self::deliverInContext()} (the ports) from host code
     *
     * @throws FileNotDeliverable when the variant cannot be served or the profile is not connectable any more
     */
    public function prepare(StoredFile $file, string $variant, ?string $range = null, ?bool $inline = null, ?int $ttl = null): DeliverableFile
    {
        $object     = $file->servedVariant($variant) ?? throw new FileNotDeliverable('No such file variant.');
        $settings   = $this->profiles->settingsOf($file->getProfile()) ?? throw new FileNotDeliverable('The bucket of this file is not connectable.');
        $connection = $this->storage->connection($settings);
        $ttl        = $ttl === null ? $this->ttl : max(1, min($ttl, $this->ttl));
        $asUploaded = $file->isStoredAsUploaded();
        $type       = $asUploaded ? MediaTypes::served($object['mime']) : (in_array($object['mime'], ImageProcessor::MIME_TYPES, true) ? $object['mime'] : 'application/octet-stream');
        // Image uploads keep their 1.4 disposition on the bundle's own route; an explicit choice is checked for safety.
        $disposition = $this->disposition($file, $object['mime'], $inline === null && !$asUploaded ? $this->disposition : $this->dispositionType($object, $inline));
        $maxAge      = max(0, $ttl - 60);
        if ($this->profiles->deliveryOf($file->getProfile()) === ConfigProfiles::DELIVERY_REDIRECT) {
            // Image uploads keep their 1.4 pre-signed type (the stored, sniffed one); generic files the served type.
            return DeliverableFile::redirect($connection->presign($object['key'], $asUploaded ? $type : $object['mime'], $disposition, new DateTimeImmutable('+' . $ttl . ' seconds')), $maxAge);
        }
        $key     = $object['key'];
        $size    = $object['size'];
        $headers = [
            'Content-Length'               => (string) $size,
            'Content-Type'                 => $type,
            'Content-Disposition'          => $disposition,
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => self::SANDBOX_CSP,
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($asUploaded) {
            // 2.2: generic files advertise ranges (media seeking); image downloads keep their 1.4 headers.
            $headers['Accept-Ranges'] = 'bytes';
        }
        $part = ByteRange::parse($range, $size);
        if ($part === false) {
            unset($headers['Content-Length']);

            return DeliverableFile::unsatisfiable($size, $maxAge, $headers);
        }
        if ($part instanceof ByteRange) {
            unset($headers['Content-Length']);
            $headers['Accept-Ranges'] = 'bytes';
            $start                    = $part->start;
            $length                   = $part->length();

            // @igor-ignore - The closure is the body source of this delivery only (kept by the per-request VO).
            return DeliverableFile::partial(static fn (): iterable => $connection->readRange($key, $length, $start, false), $part, $size, $maxAge, $headers);
        }

        // @igor-ignore - The closure is the body source of this delivery only (kept by the per-request VO).
        return DeliverableFile::stream(static fn (): iterable => $connection->readRange($key, $size), $size, $maxAge, $headers);
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

    /**
     * `attachment` or `inline` for an object (2.2): inline only when asked (or configured) **and** safe.
     *
     * @param array{mime: string, reencoded: bool} $object
     */
    private function dispositionType(array $object, ?bool $inline): string
    {
        $wanted = $inline ?? $this->disposition === HeaderUtils::DISPOSITION_INLINE;

        return $wanted && MediaTypes::inlineSafe($object['mime'], $object['reencoded']) ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
    }

    private function disposition(StoredFile $file, string $mime, string $type): string
    {
        if ($file->isStoredAsUploaded()) {
            return self::fileDisposition($type, $file->getOriginalName(), $mime);
        }
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $base      = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getOriginalName(), PATHINFO_FILENAME)), '._');
        $ascii     = ($base === '' ? StoredFile::ANONYMOUS_NAME : $base) . '.' . $extension;

        return HeaderUtils::makeDisposition($type, $ascii);
    }

    /**
     * 2.2: the disposition of a generic file: its stored name as `filename*` (RFC 6266 / RFC 8187, UTF-8) and an ASCII
     * `filename` fallback (unsafe characters replaced, the extension kept when it is 1–16 letters or digits; an active
     * type always gets `.bin`, so a saved file never opens as a page).
     */
    public static function fileDisposition(string $type, string $name, string $mime): string
    {
        $active    = MediaTypes::isActive($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $suffix    = match (true) {
            $active                                             => '.bin',
            preg_match('/^[a-z0-9]{1,16}$/D', $extension) === 1 => '.' . $extension,
            default                                             => $extension === '' ? '' : '.bin',
        };
        $base  = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($name, PATHINFO_FILENAME)), '._');
        $ascii = ($base === '' ? StoredFile::FILE_NAME : $base) . $suffix;

        // An active type is offered under its ASCII name only (never `.html` or `.svg`).
        return HeaderUtils::makeDisposition($type, $active ? $ascii : (string) preg_replace('~["\\\\/\x00-\x1F\x7F]+~', '_', $name), $ascii);
    }
}
