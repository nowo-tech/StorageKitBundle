<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\PersonalData;

use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class UploaderIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: bool}>
     */
    public static function values(): iterable
    {
        yield 'plain' => ['alice@example.com', true];
        yield 'inner space' => ['Alice Smith', true];
        yield 'accented' => ['álice', true];
        yield 'key' => ['App\Entity\User:5', true];
        yield '180 characters' => [str_repeat('é', 180), true];
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield 'trailing space' => ['alice ', false];
        yield 'leading space' => [' alice', false];
        yield 'trailing tab' => ["alice\t", false];
        yield 'trailing nbsp' => ["alice\u{00A0}", false];
        yield 'ideographic space' => ["\u{3000}alice", false];
        yield 'newline inside' => ["ali\nce", false];
        yield 'nul inside' => ["ali\0ce", false];
        yield 'zero width space' => ["ali\u{200B}ce", false];
        yield 'bidi mark' => ["\u{202E}alice", false];
        yield 'line separator' => ["ali\u{2028}ce", false];
        yield 'invalid utf-8' => ["ali\xC3ce", false];
        yield '181 characters' => [str_repeat('a', 181), false];
    }

    #[DataProvider('values')]
    public function testStorable(?string $value, bool $storable): void
    {
        self::assertSame($storable ? $value : null, UploaderIdentity::storable($value));
    }

    public function testMatchesIsByteExact(): void
    {
        // Identifier only.
        self::assertTrue(UploaderIdentity::matches('alice', null, 'alice', null));
        self::assertTrue(UploaderIdentity::matches('alice', 'k', 'alice', ''));
        self::assertFalse(UploaderIdentity::matches('Alice', null, 'alice', null));
        self::assertFalse(UploaderIdentity::matches('alice ', null, 'alice', null));
        self::assertFalse(UploaderIdentity::matches('álice', null, 'alice', null));
        self::assertFalse(UploaderIdentity::matches(null, null, 'alice', null));
        // With a key: the key, or no key and the identifier.
        self::assertTrue(UploaderIdentity::matches('renamed', 'App:5', 'alice', 'App:5'));
        self::assertTrue(UploaderIdentity::matches('alice', null, 'alice', 'App:5'));
        self::assertFalse(UploaderIdentity::matches('alice', 'App:6', 'alice', 'App:5'), 'another key is never matched by identifier');
        self::assertFalse(UploaderIdentity::matches('x', 'app:5', 'alice', 'App:5'));
        self::assertFalse(UploaderIdentity::matches('x', 'App:5 ', 'alice', 'App:5'));
        self::assertFalse(UploaderIdentity::matches('Alice', null, 'alice', 'App:5'));
    }
}
