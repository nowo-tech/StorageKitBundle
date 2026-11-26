<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Nowo\StorageKitBundle\Entity\StoredFile;

use function basename;
use function ltrim;
use function mb_check_encoding;
use function mb_substr;
use function preg_replace;
use function str_replace;
use function trim;

/**
 * The name kept with a generic file (2.2, {@see FileUploaderInterface}). It is a label only: never a path or an object
 * key (keys are server-generated `yyyy/mm/<uuid>`), and the download sends it in `Content-Disposition` only.
 *
 * The last path segment is kept (`/` and `\` split), control characters (C0, DEL, C1) and Unicode format characters
 * (bidi overrides such as RLO, zero-width characters, the BOM) are removed, `:` becomes `_`, leading dots and edge
 * white space are trimmed, and the result is cut to {@see self::MAX} characters. Invalid UTF-8 or an empty result
 * gives {@see StoredFile::FILE_NAME}. Stateless.
 */
final class FileNames
{
    /** Longest kept name, in characters (the column holds 255). */
    public const MAX = 200;

    /**
     * The cleaned name.
     */
    public static function clean(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return StoredFile::FILE_NAME;
        }
        $name = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\p{Cf}]+/u', '', basename(str_replace('\\', '/', $name)));
        $name = trim(ltrim(trim(str_replace(':', '_', $name)), '.'));
        $name = trim(mb_substr($name, 0, self::MAX));

        return $name === '' ? StoredFile::FILE_NAME : $name;
    }
}
