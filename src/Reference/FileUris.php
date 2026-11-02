<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use InvalidArgumentException;

use function array_slice;
use function array_unique;
use function array_values;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strtolower;

/**
 * How texts point at stored files: the URI `<scheme>:<uuid>` (`uri_scheme`, e.g. `storage-file:` — the URL is built
 * at render time for the viewer) or the download path `<files path_prefix>/files/<uuid>/<variant>` that editors such as
 * MilkdownEditor insert from the upload answer. Both forms are recognised when a text is saved (references) and in
 * {@see self::plainText()}.
 */
final readonly class FileUris
{
    /** URI scheme syntax. */
    public const SCHEME_PATTERN = '/^[a-z][a-z0-9+.\-]{1,31}$/';

    /** Most file ids taken from one text. */
    public const MAX_IDS = 200;

    /** Placeholder of an image in plain text (e-mails, push notifications, excerpts). */
    public const PLAIN = '[image]';

    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private string $downloadPath;

    /**
     * @param string $scheme `uri_scheme`
     * @param string $filesPrefix `upload.path_prefix` (download paths are `<prefix>/files/<uuid>/<variant>`)
     *
     * @throws InvalidArgumentException on a malformed scheme
     */
    public function __construct(
        private string $scheme = 'storage-file',
        string $filesPrefix = '/storage',
    ) {
        if (preg_match(self::SCHEME_PATTERN, $scheme) !== 1) {
            throw new InvalidArgumentException('The URI scheme must be 2-32 characters: a lowercase letter, then letters, digits, "+", "." or "-".');
        }
        $this->downloadPath = rtrim($filesPrefix, '/') . '/files/';
    }

    /**
     * The scheme (without the colon).
     */
    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * `<scheme>:<uuid>`.
     */
    public function uri(string $uuid): string
    {
        return $this->scheme . ':' . strtolower($uuid);
    }

    /**
     * The file id of a `<scheme>:<uuid>` URI or a download path / URL, or null.
     */
    public function storedId(string $url): ?string
    {
        $pattern = '#^(?:' . preg_quote($this->scheme, '#') . ':(' . self::UUID . ')|(?:https?://[^/\s]+)?' . preg_quote($this->downloadPath, '#') . '(' . self::UUID . ')/[a-z]+)$#';
        if (preg_match($pattern, $url, $m) !== 1) {
            return null;
        }

        return strtolower(($m[1] ?? '') !== '' ? $m[1] : $m[2]);
    }

    /**
     * Whether a text may point at a stored file (cheap check before parsing).
     */
    public function mentions(mixed $text): bool
    {
        return is_string($text) && (str_contains($text, $this->scheme . ':') || str_contains($text, $this->downloadPath));
    }

    /**
     * Every stored file a text points at (at most {@see self::MAX_IDS}).
     *
     * @return list<string>
     */
    public function storedIds(string $text): array
    {
        $pattern = '#\((?:' . preg_quote($this->scheme, '#') . ':(' . self::UUID . ')|(?:https?://[^/\s)]+)?' . preg_quote($this->downloadPath, '#') . '(' . self::UUID . ')/[a-z]+)[\s)]#';
        preg_match_all($pattern, $text . ' ', $m);
        $ids = [];
        foreach ($m[1] as $i => $id) {
            $ids[] = strtolower($id !== '' ? $id : $m[2][$i]);
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_IDS);
    }

    /**
     * Inline Markdown images (`![alt](url)`) replaced by {@see self::PLAIN}, for texts sent outside the application.
     */
    public static function plainText(string $text): string
    {
        return (string) preg_replace('/!\[(?:[^\]\\\\]|\\\\.)*\]\([^)\s]*(?:\s+"[^"]*")?\)/u', self::PLAIN, $text);
    }
}
