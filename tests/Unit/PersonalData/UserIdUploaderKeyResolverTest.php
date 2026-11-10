<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\PersonalData;

use Nowo\StorageKitBundle\PersonalData\UserIdUploaderKeyResolver;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

use function str_repeat;

final class UserIdUploaderKeyResolverTest extends TestCase
{
    public function testTheUserIdIsTheKey(): void
    {
        $keys = new UserIdUploaderKeyResolver();
        $uuid = Uuid::v4();
        $user = new class($uuid) {
            public function __construct(private readonly Uuid $id)
            {
            }

            public function getId(): Uuid
            {
                return $this->id;
            }
        };

        self::assertSame('42', $keys->keyOf(new AppUser(42, 'a@example.com')));
        self::assertSame('abc', $keys->keyOf(new AppUser(' abc ', 'a@example.com')));
        self::assertSame($uuid->toRfc4122(), $keys->keyOf($user));
    }

    public function testUsersWithoutAUsableIdHaveNoKey(): void
    {
        $keys = new UserIdUploaderKeyResolver();
        $user = new class {
            /**
             * @return list<int>
             */
            public function getId(): array
            {
                return [1];
            }
        };

        self::assertNull($keys->keyOf(new InMemoryUser('alice', null)));
        self::assertNull($keys->keyOf(new stdClass()));
        self::assertNull($keys->keyOf(new AppUser(null, 'a@example.com')));
        self::assertNull($keys->keyOf(new AppUser('  ', 'a@example.com')));
        self::assertNull($keys->keyOf(new AppUser(str_repeat('x', 181), 'a@example.com')), 'never cut');
        self::assertNull($keys->keyOf($user));
    }
}
