<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Reference;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Reference\FileUris;
use PHPUnit\Framework\TestCase;

use function range;
use function sprintf;

final class FileUrisTest extends TestCase
{
    private const A = '0b6f2a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const B = '1b6f2a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    public function testBothFormsAreRecognised(): void
    {
        $uris = new FileUris('storage-file', '/storage/');
        $text = sprintf("![a](storage-file:%s) and ![b](/storage/files/%s/preview \"t\")\n![c](https://app.example.com/storage/files/%s/thumb) ![x](storage-file:nope) [link](https://elsewhere/%s)", self::A, self::B, self::A, self::B);

        self::assertSame([self::A, self::B], $uris->storedIds($text));
        self::assertSame('storage-file', $uris->scheme());
        self::assertSame('storage-file:' . self::A, $uris->uri(strtoupper(self::A)));
        self::assertSame(self::A, $uris->storedId('storage-file:' . strtoupper(self::A)));
        self::assertSame(self::B, $uris->storedId('/storage/files/' . self::B . '/original'));
        self::assertSame(self::B, $uris->storedId('https://h.example/storage/files/' . self::B . '/thumb'));
        self::assertNull($uris->storedId('https://evil.example/' . self::A));
        self::assertTrue($uris->mentions('x storage-file:y'));
        self::assertTrue($uris->mentions('x /storage/files/y'));
        self::assertFalse($uris->mentions('plain'));
        self::assertFalse($uris->mentions(null));
    }

    public function testAtMostTwoHundredIdsAreTaken(): void
    {
        $text = '';
        foreach (range(1, 250) as $i) {
            $text .= sprintf('![](storage-file:00000000-0000-4000-8000-%012d) ', $i);
        }

        self::assertCount(FileUris::MAX_IDS, (new FileUris())->storedIds($text));
    }

    public function testPlainTextHidesImages(): void
    {
        self::assertSame('see [image] and [image].', FileUris::plainText('see ![a](storage-file:' . self::A . ') and ![b \] c](https://x/y.png "t").'));
    }

    public function testTheSchemeIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileUris('Bad Scheme');
    }
}
