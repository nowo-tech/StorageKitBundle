<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Upload\ImageProcessor;

use function in_array;
use function preg_match;
use function str_ends_with;
use function strlen;

/**
 * The media type rules of stored files (2.2, generic files), in one place so uploads and downloads agree:
 *
 * - **Syntax.** A stored type is a bare `type/subtype` in lower case (RFC 6838 restricted names, no parameters such as
 *   `; charset=`), at most {@see self::MAX_LENGTH} characters. Types are compared exactly (`===`): `Image/PNG` is not
 *   `image/png`, so a context's allow-list decides byte for byte (REQ-SEC-006).
 * - **Active content** ({@see self::isActive()}): HTML, XHTML, SVG, XML (and every `+xml` type), XSL and script types
 *   are never sent with their own type. They are served as `application/octet-stream`, `attachment`, so a browser
 *   never renders or runs them, whatever the context allowed at upload.
 * - **Inline** ({@see self::inlineSafe()}): only raster images StorageKit checked by magic bytes and header and serves
 *   under that type with `nosniff` (re-encoded originals, GIF / animated WebP image uploads kept as animations, variants
 *   written through the variant port; audit ZCK-SK-I3) and the audio / video types of {@see self::INLINE_MEDIA} (played through
 *   `<audio>` / `<video>`) may be served `inline`. Everything else is `attachment`.
 *
 * Stateless (static methods and constants only).
 */
final class MediaTypes
{
    /** Longest stored type (the `mime_type` column holds 127 characters since 2.2). */
    public const MAX_LENGTH = 127;

    /** Served instead of an active type. */
    public const OCTET_STREAM = 'application/octet-stream';

    /** Audio and video types that may be served `inline` (a media element plays them; a browser never renders them as a page). */
    public const INLINE_MEDIA = [
        'audio/aac',
        'audio/flac',
        'audio/mp4',
        'audio/mpeg',
        'audio/ogg',
        'audio/opus',
        'audio/wav',
        'audio/webm',
        'audio/x-wav',
        'video/mp4',
        'video/ogg',
        'video/webm',
    ];

    /** Types a browser may render or run as a document (served as {@see self::OCTET_STREAM}, attachment). */
    public const ACTIVE = [
        'application/ecmascript',
        'application/javascript',
        'application/x-javascript',
        'application/xhtml+xml',
        'application/xml',
        'application/xslt+xml',
        'image/svg+xml',
        'multipart/x-mixed-replace',
        'text/ecmascript',
        'text/html',
        'text/javascript',
        'text/xml',
        'text/xsl',
    ];

    private const PATTERN = '#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,62}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,62}$#D';

    /**
     * Whether `$type` is a storable type: a bare, lower-case `type/subtype`, at most {@see self::MAX_LENGTH} characters.
     */
    public static function isValid(string $type): bool
    {
        return strlen($type) <= self::MAX_LENGTH && preg_match(self::PATTERN, $type) === 1;
    }

    /**
     * Whether `$type` is active content (HTML, SVG, XML, script…): it is never served with its own type.
     */
    public static function isActive(string $type): bool
    {
        return in_array($type, self::ACTIVE, true) || str_ends_with($type, '+xml') || !self::isValid($type);
    }

    /**
     * Whether `$type` is one of the raster image types StorageKit decodes and re-encodes (PNG, JPEG, GIF, WebP).
     */
    public static function isRasterImage(string $type): bool
    {
        return in_array($type, ImageProcessor::MIME_TYPES, true);
    }

    /**
     * Whether `$type` is an audio or video type of {@see self::INLINE_MEDIA}.
     */
    public static function isInlineMedia(string $type): bool
    {
        return in_array($type, self::INLINE_MEDIA, true);
    }

    /**
     * The `Content-Type` a stored type is served with: the type itself, or {@see self::OCTET_STREAM} for active content.
     */
    public static function served(string $type): string
    {
        return self::isActive($type) ? self::OCTET_STREAM : $type;
    }

    /**
     * Whether an object of `$type` may be served `inline`: a raster image StorageKit checked (`$reencoded`: re-encoded, or verified by magic bytes and header and served under its raster type), or an
     * audio / video type of {@see self::INLINE_MEDIA}. Never active content.
     */
    public static function inlineSafe(string $type, bool $reencoded): bool
    {
        if (self::isActive($type)) {
            return false;
        }

        return ($reencoded && self::isRasterImage($type)) || self::isInlineMedia($type);
    }
}
