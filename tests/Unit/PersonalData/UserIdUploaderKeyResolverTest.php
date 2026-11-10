<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\PersonalData;

use Nowo\StorageKitBundle\PersonalData\UserIdUploaderKeyResolver;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Nowo\StorageKitBundle\Tests\Fixtures\Users\Admin;
use Nowo\StorageKitBundle\Tests\Fixtures\Users\Customer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

use function hash;
use function str_repeat;

require_once __DIR__ . '/../../Fixtures/Users/Proxy/CustomerProxy.php';

final class UserIdUploaderKeyResolverTest extends TestCase
{
    public function testTheKeyIsTheUserClassAndId(): void
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

        self::assertSame(AppUser::class . ':42', $keys->keyOf(new AppUser(42, 'a@example.com')));
        self::assertSame(AppUser::class . ':abc', $keys->keyOf(new AppUser(' abc ', 'a@example.com')));
        self::assertSame('sha256:' . hash('sha256', $user::class . ':' . $uuid->toRfc4122()), $keys->keyOf($user), 'an anonymous class name holds a NUL byte, which PostgreSQL refuses in VARCHAR');
    }

    public function testTwoUserClassesWithTheSameIdGetDifferentKeys(): void
    {
        $keys = new UserIdUploaderKeyResolver();

        self::assertSame(Admin::class . ':5', $keys->keyOf(new Admin(5, 'admin@example.com')));
        self::assertSame(Customer::class . ':5', $keys->keyOf(new Customer(5, 'customer@example.com')));
    }

    public function testADoctrineProxyGivesTheKeyOfItsEntity(): void
    {
        $proxy = new \Proxies\__CG__\Nowo\StorageKitBundle\Tests\Fixtures\Users\Customer(5, 'customer@example.com');

        self::assertSame(Customer::class . ':5', (new UserIdUploaderKeyResolver())->keyOf($proxy));
        self::assertSame(Customer::class, UserIdUploaderKeyResolver::realClass($proxy));
    }

    public function testALongKeyIsHashedNeverCut(): void
    {
        $id = str_repeat('x', 200);

        self::assertSame('sha256:' . hash('sha256', AppUser::class . ':' . $id), (new UserIdUploaderKeyResolver())->keyOf(new AppUser($id, 'a@example.com')));
    }

    public function testUnusableIdsGiveNoKeyAndAreLoggedOncePerClass(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $classes = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->classes[] = (string) ($context['class'] ?? '');
            }
        };
        $keys  = new UserIdUploaderKeyResolver($logger);
        $users = [
            'array id' => new class {
                /**
                 * @return list<int>
                 */
                public function getId(): array
                {
                    return [1];
                }
            },
            'private' => new class {
                private function getId(): int
                {
                    return 1;
                }

                public function id(): int
                {
                    return $this->getId();
                }
            },
            'protected' => new class {
                protected function getId(): int
                {
                    return 1;
                }
            },
            'argument' => new class {
                public function getId(int $x): int
                {
                    return $x;
                }
            },
            'uninitialised' => new class {
                /** @phpstan-ignore property.onlyRead (uninitialised on purpose) */
                private int $id;

                public function getId(): int
                {
                    return $this->id;
                }
            },
            'throws' => new class {
                public function getId(): int
                {
                    throw new RuntimeException('no id');
                }
            },
            'bad string' => new class {
                public function getId(): Stringable
                {
                    return new class implements Stringable {
                        public function __toString(): string
                        {
                            throw new RuntimeException('no string');
                        }
                    };
                }
            },
        ];
        foreach ($users as $case => $user) {
            self::assertNull($keys->keyOf($user), $case);
        }
        self::assertNull($keys->keyOf(new InMemoryUser('alice', null)));
        self::assertNull($keys->keyOf(new InMemoryUser('bob', null)));
        self::assertNull($keys->keyOf(new stdClass()));
        self::assertNull($keys->keyOf(new AppUser(null, 'a@example.com')));
        self::assertNull($keys->keyOf(new AppUser('  ', 'a@example.com')));
        self::assertCount(10, $logger->classes, 'one warning per class (InMemoryUser and AppUser once each)');

        $keys->reset();
        self::assertNull($keys->keyOf(new stdClass()));
        self::assertCount(11, $logger->classes);
    }
}
