<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

/**
 * Boards of the tests: who reads / writes each one and which storage scope it belongs to.
 */
final class Boards
{
    /** @var array<string, array{scope: string|null, readers: list<string>, writers: list<string>, anonymous: bool}> */
    public array $boards = [
        'b1'   => ['scope' => null, 'readers' => ['alice', 'bob', 'admin'], 'writers' => ['alice', 'admin'], 'anonymous' => false],
        'b2'   => ['scope' => null, 'readers' => ['bob'], 'writers' => ['bob'], 'anonymous' => false],
        'acme' => ['scope' => 'acme', 'readers' => ['alice', 'scopeadmin'], 'writers' => ['alice', 'scopeadmin'], 'anonymous' => false],
        'pub'  => ['scope' => null, 'readers' => [], 'writers' => ['alice'], 'anonymous' => true],
    ];
}
