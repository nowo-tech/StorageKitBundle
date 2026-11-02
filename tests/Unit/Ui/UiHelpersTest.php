<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Ui;

use Nowo\StorageKitBundle\Ui\Bytes;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use PHPUnit\Framework\TestCase;

final class UiHelpersTest extends TestCase
{
    public function testBytes(): void
    {
        self::assertSame('0 B', Bytes::format(0));
        self::assertSame('1023 B', Bytes::format(1023));
        self::assertSame('1.5 KiB', Bytes::format(1536));
        self::assertSame('2.0 MiB', Bytes::format(2 * 1024 ** 2));
        self::assertSame('1024.0 TiB', Bytes::format(1024 ** 5));
    }

    public function testPagesAndTemplates(): void
    {
        $pages = new StorageKitPages('layout.html.twig', ['move' => 'app/move.html.twig', 'settings' => '']);

        self::assertSame('app/move.html.twig', $pages->template('move'));
        self::assertSame(StorageKitPages::TEMPLATES['settings'], $pages->template('settings'));
        self::assertSame('other', $pages->template('other'));
        self::assertSame('layout.html.twig', $pages->layout());
        self::assertNull((new StorageKitPages(''))->layout());
        self::assertSame('settings.title', StorageKitPages::label('settings.title')->key);
    }
}
