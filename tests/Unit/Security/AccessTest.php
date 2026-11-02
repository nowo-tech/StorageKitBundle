<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Security;

use Nowo\StorageKitBundle\Scope\DenyAllScopeAccessChecker;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Security\AllowAllStorageAccessChecker;
use Nowo\StorageKitBundle\Security\RoleAccessChecker;
use Nowo\StorageKitBundle\Tests\Fixtures\ScopeAccess;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;

final class AccessTest extends TestCase
{
    /**
     * @param list<string> $granted
     */
    private function checker(array $granted): AuthorizationCheckerInterface
    {
        return new class($granted) implements AuthorizationCheckerInterface {
            /** @param list<string> $granted */
            public function __construct(private array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->granted, true);
            }
        };
    }

    /**
     * @param list<string> $roles
     * @param list<string> $granted
     */
    private function guard(string $user, bool $firewall = true, array $roles = ['ROLE_ADMIN'], array $granted = ['ROLE_ADMIN', 'ROLE_USER']): AccessGuard
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null), 'main'));
        $stack   = new RequestStack();
        $request = new Request();
        if ($firewall) {
            $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        }
        $stack->push($request);

        return new AccessGuard(new RoleAccessChecker($this->checker($granted), $roles), new ScopeAccess(), new RoleAccessChecker($this->checker($granted), ['ROLE_USER']), $tokens, $stack);
    }

    public function testRolesScopesAndUploads(): void
    {
        self::assertTrue($this->guard('admin')->isGranted());
        self::assertFalse($this->guard('admin', granted: ['ROLE_USER'])->isGranted());
        self::assertFalse($this->guard('admin', roles: [])->isGranted(), 'an empty role list denies');
        self::assertFalse($this->guard('admin', roles: [''])->isGranted());
        self::assertTrue($this->guard('scopeadmin')->isScopeGranted('acme'));
        self::assertFalse($this->guard('scopeadmin')->isScopeGranted('other'));
        self::assertFalse($this->guard('scopeadmin')->isScopeGranted('bad/scope'));
        self::assertTrue($this->guard('alice')->canUpload());
        self::assertFalse($this->guard('alice', granted: [])->canUpload());
    }

    public function testATokenWithoutFirewallIsNotTrusted(): void
    {
        $guard = $this->guard('admin', false);

        self::assertNull($guard->user());
        self::assertFalse($guard->isGranted());
        self::assertFalse($guard->canUpload());
        self::assertFalse($guard->isScopeGranted('acme'));
        self::assertNull((new AccessGuard(new AllowAllStorageAccessChecker(), new DenyAllScopeAccessChecker(), new AllowAllStorageAccessChecker()))->user());
        $this->expectException(AccessDeniedHttpException::class);
        $guard->deny('no');
    }

    public function testDenialBehindAFirewallStartsAuthentication(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->guard('admin')->deny('no');
    }

    public function testDefaultsFailClosed(): void
    {
        self::assertTrue((new AllowAllStorageAccessChecker())->canAccess(null));
        self::assertFalse((new DenyAllScopeAccessChecker())->canManage(new stdClass(), 'acme'));
        self::assertFalse((new RoleAccessChecker($this->checker(['ROLE_ADMIN']), ['ROLE_ADMIN']))->canAccess(null));
    }
}
