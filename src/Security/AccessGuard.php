<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Security;

use Nowo\StorageKitBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;

/**
 * Applies the access rules to the current user: the instance pages ({@see StorageAccessCheckerInterface}), a scope's
 * pages ({@see ScopeAccessCheckerInterface}) and uploads (`upload.access_roles`, never empty). The token is trusted
 * only when a firewall matched the main request.
 */
final readonly class AccessGuard
{
    /**
     * @param StorageAccessCheckerInterface $uploadChecker the `upload.access_roles` checker
     */
    public function __construct(
        private StorageAccessCheckerInterface $instanceChecker,
        private ScopeAccessCheckerInterface $scopeChecker,
        private StorageAccessCheckerInterface $uploadChecker,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * The instance pages are open for the current user.
     */
    public function isGranted(): bool
    {
        return $this->instanceChecker->canAccess($this->user());
    }

    /**
     * The pages of `$scope` are open for the current user (signed in, and the scope checker agrees).
     */
    public function isScopeGranted(string $scope): bool
    {
        $user = $this->user();

        return $user !== null && StorageScope::isValid($scope) && $this->scopeChecker->canManage($user, $scope);
    }

    /**
     * The current user (signed in) may upload at all; the context then decides where.
     */
    public function canUpload(): bool
    {
        $user = $this->user();

        return $user !== null && $this->uploadChecker->canAccess($user);
    }

    /**
     * Current security user behind a firewall, or null.
     */
    public function user(): ?object
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if (!$mainRequest instanceof Request || !$mainRequest->attributes->has('_firewall_context')) {
            return null;
        }
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * 403 (or the firewall entry point for anonymous users behind a firewall).
     */
    public function deny(string $message): never
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if ($mainRequest instanceof Request && $mainRequest->attributes->has('_firewall_context')) {
            throw new AccessDeniedException($message);
        }

        throw new AccessDeniedHttpException($message);
    }
}
