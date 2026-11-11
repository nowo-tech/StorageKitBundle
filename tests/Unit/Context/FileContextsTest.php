<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Context;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Scope\TargetScopeResolver;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\NoteContext;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class FileContextsTest extends TestCase
{
    private function file(string $board): StoredFile
    {
        return new StoredFile(new StorageProfile(), 'board', $board, 'k', 'a.png', 'image/png', 1, 1, 1, 'x');
    }

    public function testReadsAreBatchedPerTypeAndMemoisedPerViewer(): void
    {
        $context  = new NoteContext(new Boards());
        $contexts = new FileContexts([$context]);
        $files    = [$this->file('b1'), $this->file('b1'), $this->file('b2'), new StoredFile(new StorageProfile(), 'other', 'x', 'k', 'n', 'image/png', 1, 1, 1, 'x')];
        $alice    = new InMemoryUser('alice', null);

        $readable = $contexts->readable($alice, $files);
        self::assertSame([true, true, false, false], array_values($readable));
        self::assertSame(1, $context->readCalls);
        $contexts->readable($alice, $files);
        self::assertSame(1, $context->readCalls);
        $contexts->readable(new InMemoryUser('bob', null), $files);
        self::assertSame(2, $context->readCalls);
        self::assertSame([false, false, false, false], array_values($contexts->readable(null, $files)));
        self::assertSame([false, false, false, false], array_values($contexts->readable(new stdClass(), $files)));
        $contexts->reset();
        $contexts->readable($alice, $files);
        self::assertSame(5, $context->readCalls);
        self::assertSame(['board'], $contexts->types());
        self::assertSame($context, $contexts->get('board'));
        self::assertNull($contexts->get('nope'));
    }

    public function testTwoUsersSharingAnIdentifierNeverShareADecision(): void
    {
        // Audit LR-S-L1: an admin-provider "alice" and a customer-provider "alice" in the same boundary.
        $context = $this->createMock(FileContextInterface::class);
        $context->method('type')->willReturn('board');
        $context->expects(self::exactly(4))->method('readable')
            ->willReturnCallback(static fn (?object $viewer, array $ids): array => $viewer instanceof InMemoryUser ? $ids : []);
        $contexts = new FileContexts([$context]);
        $file     = $this->file('b1');
        $other    = new AppUser(7, 'alice');

        self::assertTrue($contexts->readable(new InMemoryUser('alice', null), [$file])[$file->getUuid()]);
        self::assertFalse($contexts->readable($other, [$file])[$file->getUuid()], 'asked again: another user object');
        self::assertFalse($contexts->readable($other, [$file])[$file->getUuid()], 'memoised for that object');
        self::assertFalse($contexts->readable(null, [$file])[$file->getUuid()]);
        self::assertFalse($contexts->readable(null, [$file])[$file->getUuid()], 'memoised for anonymous visitors');
        self::assertFalse($contexts->readable(new stdClass(), [$file])[$file->getUuid()]);
    }

    public function testSyntaxChecks(): void
    {
        self::assertTrue(FileContexts::isType('task_board'));
        self::assertFalse(FileContexts::isType('Task'));
        self::assertTrue(FileContexts::isId('a-1.b:c_d'));
        self::assertFalse(FileContexts::isId('a/b'));
        self::assertFalse(FileContexts::isId(''));
    }

    public function testDuplicateTypesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileContexts([new NoteContext(new Boards()), new NoteContext(new Boards())]);
    }

    public function testTargetsAndScopes(): void
    {
        $target = new FileContextTarget('acme', 'owner-1');

        self::assertSame('acme', (new TargetScopeResolver())->scopeOf($target));
        self::assertNull((new FileContextTarget())->scope);
        self::assertSame('instance', StorageScope::suffix(null));
        self::assertSame('scope/acme', StorageScope::suffix('acme'));
        self::assertSame('acme', StorageScope::assertValid('acme'));
        self::assertFalse(StorageScope::isValid('a/b'));
        $this->expectException(InvalidArgumentException::class);
        new FileContextTarget('a/b');
    }

    public function testOwnerKeysAreBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileContextTarget(null, str_repeat('x', 65));
    }

    public function testScopeKeysAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageScope::assertValid('');
    }
}
