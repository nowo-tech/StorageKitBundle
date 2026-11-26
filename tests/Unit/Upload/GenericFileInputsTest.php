<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Upload;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\FileNames;
use Nowo\StorageKitBundle\Upload\GdImageThumbnailer;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\ProcessedImage;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function bin2hex;
use function fclose;
use function file_put_contents;
use function getimagesizefromstring;
use function random_bytes;
use function str_repeat;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 2.2: the inputs of generic uploads — the cleaned name, the local source and the thumbnail port.
 */
final class GenericFileInputsTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sk220u');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }

    private function refused(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testNamesAreLabelsNeverPaths(): void
    {
        self::assertSame('report.pdf', FileNames::clean('report.pdf'));
        self::assertSame('passwd', FileNames::clean('../../etc/passwd'));
        self::assertSame('evil.pdf', FileNames::clean('C:\\Users\\x\\evil.pdf'));
        self::assertSame('a_b.txt', FileNames::clean('a:b.txt'));
        self::assertSame('fdp.exe', FileNames::clean("\u{202E}fdp.exe"));
        self::assertSame('ab.txt', FileNames::clean("a\u{200B}b\u{FEFF}.txt\0"));
        self::assertSame('htaccess', FileNames::clean('.htaccess'));
        self::assertSame('file', FileNames::clean('...'));
        self::assertSame('file', FileNames::clean(''));
        self::assertSame('file', FileNames::clean("\xC3\x28"));
        self::assertSame(str_repeat('é', FileNames::MAX), FileNames::clean(str_repeat('é', 500)));
    }

    public function testASourceIsARegularFileOfTheDeclaredSize(): void
    {
        $path   = $this->file('hello');
        $source = StoredFileSource::fromPath($path);
        self::assertSame([$path, 5], [$source->path, $source->size]);
        $handle = $source->open();
        fclose($handle);

        $this->refused(UploadRefused::SOURCE, static fn () => (new StoredFileSource($path, 4))->open());
        $this->refused(UploadRefused::SOURCE, static fn () => (new StoredFileSource(sys_get_temp_dir(), 4096))->open());
        $this->refused(UploadRefused::SOURCE, static fn () => (new StoredFileSource('/nonexistent/sk220', 1))->open());
        $link          = sys_get_temp_dir() . '/sk220-link-' . bin2hex(random_bytes(4));
        $this->paths[] = $link;
        symlink($path, $link);
        $this->refused(UploadRefused::SOURCE, static fn () => (new StoredFileSource($link, 5))->open());

        foreach ([static fn (): StoredFileSource => new StoredFileSource('', 1), static fn (): StoredFileSource => new StoredFileSource("a\0b", 1), static fn (): StoredFileSource => new StoredFileSource('/a', -1), static fn (): StoredFileSource => StoredFileSource::fromPath($link), static fn (): StoredFileSource => StoredFileSource::fromPath(sys_get_temp_dir()), static fn (): StoredFileSource => StoredFileSource::fromPath('')] as $build) {
            try {
                $build();
                self::fail('built');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testTheThumbnailIsReEncodedAndBounded(): void
    {
        $thumbnailer = new GdImageThumbnailer(new ImageProcessor(), 1024 * 1024);

        $png = $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::png(400, 100))), 100);
        self::assertSame(['image/png', 100, 25], [$png->original['mime'], $png->original['width'], $png->original['height']]);
        self::assertSame([100, 25], array_slice((array) getimagesizefromstring($png->original['bytes']), 0, 2));

        $small = $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::webp(30, 20))), 100);
        self::assertSame(['image/webp', 30, 20], [$small->original['mime'], $small->original['width'], $small->original['height']], 'never enlarged');

        $gif = $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::gif(30, 20))), 100);
        self::assertSame(['image/png', 30, 20], [$gif->original['mime'], $gif->original['width'], $gif->original['height']], 'a GIF becomes a PNG of its first frame');
        $gifBig = $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::gif(300, 20))), 100);
        self::assertSame('image/png', $gifBig->original['mime']);

        $jpeg = $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::jpeg(64, 32))), 16);
        self::assertSame(['image/jpeg', 16, 8], [$jpeg->original['mime'], $jpeg->original['width'], $jpeg->original['height']]);

        $this->refused(UploadRefused::EMPTY, fn (): ProcessedImage => $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file('')), 100));
        $this->refused(UploadRefused::TYPE, fn (): ProcessedImage => $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file('<svg/>')), 100));
        $this->refused(UploadRefused::SIZE, fn (): ProcessedImage => (new GdImageThumbnailer(new ImageProcessor(), 10))->thumbnail(StoredFileSource::fromPath($this->file(Images::png())), 100));
        $this->refused(UploadRefused::PIXELS, fn (): ProcessedImage => (new GdImageThumbnailer(new ImageProcessor(maxPixels: 100)))->thumbnail(StoredFileSource::fromPath($this->file(Images::png(20, 10))), 100));
        $this->refused(UploadRefused::PIXELS, fn (): ProcessedImage => (new GdImageThumbnailer(new ImageProcessor(memoryLimit: '1M')))->thumbnail(StoredFileSource::fromPath($this->file(Images::jpeg(400, 400))), 100));
        $this->refused(UploadRefused::SOURCE, fn (): ProcessedImage => $thumbnailer->thumbnail(new StoredFileSource($this->file('abc'), 2), 100));
        // A PNG header on a broken body: the header passes, GD cannot decode it.
        $this->refused(UploadRefused::TYPE, fn (): ProcessedImage => $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(substr(Images::png(20, 10), 0, 40))), 100));
        foreach ([15, 4097] as $edge) {
            try {
                $thumbnailer->thumbnail(StoredFileSource::fromPath($this->file(Images::png())), $edge);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
            }
        }
        $this->refused(UploadRefused::SIZE, static fn (): ProcessedImage => (new ImageProcessor())->thumbnail('abc', 100, 2));
        $this->refused(UploadRefused::EMPTY, static fn (): ProcessedImage => (new ImageProcessor())->thumbnail('', 100, 2));
    }
}
