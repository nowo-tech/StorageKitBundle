<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Identifier;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ExactIdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing space' => ['bob '];
        yield 'leading space' => [' bob'];
        yield 'trailing newline' => ["bob\n"];
        yield 'inner newline' => ["bo\nb"];
        yield 'NBSP' => ["bob\u{00A0}"];
        yield 'leading NBSP' => ["\u{00A0}bob"];
        yield 'ZWSP' => ["bo\u{200B}b"];
        yield 'BOM' => ["\u{FEFF}bob"];
        yield 'bidi mark' => ["bob\u{200F}"];
        yield 'line separator' => ["bo\u{2028}b"];
        yield 'NUL' => ["bo\0b"];
        yield 'invalid UTF-8' => ["bo\xC3b"];
        yield 'too long' => [str_repeat('b', 65)];
    }

    #[DataProvider('unusable')]
    public function testUnusableIdentifiersAreRefused(string $value): void
    {
        self::assertNull(ExactIdentifier::usable($value, 64));
    }

    public function testLookalikesThatAreValidIdentifiersStayDistinct(): void
    {
        foreach (['bob', 'Bob', 'bób', 'b o b', str_repeat('b', 64), 'ñandú'] as $value) {
            self::assertSame($value, ExactIdentifier::usable($value, 64));
        }
        self::assertNull(ExactIdentifier::usable(null, 64));
        self::assertNull(ExactIdentifier::usable('ñandú', 4), 'the length counts characters');
        self::assertSame('ñandú', ExactIdentifier::usable('ñandú', 5));
    }

    public function testTheExactSqlExpressionPerPlatform(): void
    {
        self::assertSame('CAST(r.source_id AS BINARY)', ExactIdentifier::sql(new MySQL80Platform(), 'r.source_id'));
        self::assertSame('CAST(owner AS BINARY)', ExactIdentifier::sql(new MariaDBPlatform(), 'owner'));
        self::assertSame('owner COLLATE BINARY', ExactIdentifier::sql(new SQLitePlatform(), 'owner'));
        self::assertSame('owner', ExactIdentifier::sql(new PostgreSQLPlatform(), 'owner'));
    }

    public function testSameIsStrict(): void
    {
        self::assertTrue(ExactIdentifier::same('bob', 'bob'));
        self::assertTrue(ExactIdentifier::same(12, '12'));
        self::assertFalse(ExactIdentifier::same('Bob', 'bob'));
        self::assertFalse(ExactIdentifier::same('bob ', 'bob'));
        self::assertFalse(ExactIdentifier::same(null, ''));
        self::assertFalse(ExactIdentifier::same(1.0, '1'));
    }
}
