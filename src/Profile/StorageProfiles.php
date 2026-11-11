<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Profile;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which bucket an upload goes to and how a profile connects.
 *
 * Resolution of a context target: its scope ({@see ScopeResolverInterface}, only with `scopes.enabled`) → that scope's
 * active database profile; a scope profile that exists but is disabled or incomplete turns uploads off for the scope
 * (no silent fallback to another owner's bucket); a scope without a profile uses the instance profile. The instance
 * profile is the config profile named by `instance_profile` (managed by the environment: its page is read-only), else
 * the instance database profile; none usable = uploads off.
 *
 * Settings: a config row uses the `profiles` tree (unrestricted); a database row uses its own fields, restricted (SSRF
 * guard) for scopes always and for the instance unless `endpoints.guard_instance_profile` is false. Database rows are
 * always delivered through the application (`proxy`): only a config profile with `delivery: redirect` sends browsers
 * to the bucket, so the CSP never opens to a host typed into a page.
 */
final class StorageProfiles implements ResetInterface
{
    /** @var array<string, false|StorageProfile> "s:<scope>" / "instance" / "c:<name>" => profile or none */
    private array $memo = [];

    /**
     * @param string|null $instanceProfile `instance_profile` (a `profiles` name), null = the database instance profile
     * @param bool $guardInstance `endpoints.guard_instance_profile`
     * @param bool $scopesEnabled `scopes.enabled`
     */
    public function __construct(
        private readonly StorageProfileRepository $profiles,
        private readonly StorageEntityManager $entityManager,
        private readonly ConfigProfileProviderInterface $config,
        private readonly ScopeResolverInterface $scopes,
        private readonly ?string $instanceProfile = null,
        private readonly bool $guardInstance = true,
        private readonly bool $scopesEnabled = false,
        private readonly ?InstanceStorageSettings $instanceSettings = null,
    ) {
    }

    /**
     * The instance profile is managed by the configuration (`instance_profile`): its page is read-only and it cannot
     * be switched from the Move page.
     */
    public function isEnvManaged(?string $scope): bool
    {
        return $scope === null && $this->instanceProfile !== null;
    }

    /**
     * Whether scope pages and scope resolution are on.
     */
    public function scopesEnabled(): bool
    {
        return $this->scopesEnabled;
    }

    /**
     * Whether the database profiles of an owner go through the SSRF guard (scopes always; the instance unless
     * `endpoints.guard_instance_profile` is false).
     */
    public function isRestricted(?string $scope): bool
    {
        return $scope !== null || $this->guardInstance;
    }

    /**
     * The connection settings of a profile; null when its fields or its config entry are not usable.
     */
    public function settingsOf(StorageProfile $profile): ?S3Settings
    {
        $name = $profile->getConfigName();
        if ($name !== null) {
            return $this->config->settings($name);
        }
        if ($profile->isSettingsBacked()) {
            return $this->instanceSettings?->settings();
        }
        try {
            return $profile->settings($this->isRestricted($profile->getScope()));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * {@see ConfigProfiles::DELIVERY_REDIRECT} (config profiles that ask for it) or {@see ConfigProfiles::DELIVERY_PROXY}.
     */
    public function deliveryOf(StorageProfile $profile): string
    {
        $name = $profile->getConfigName();

        return $name === null ? ConfigProfiles::DELIVERY_PROXY : $this->config->delivery($name);
    }

    /**
     * The profile new uploads of the target go to; null = uploads are off there.
     */
    public function forTarget(FileContextTarget $target): ?StorageProfile
    {
        $scope = $this->scopesEnabled ? $this->scopes->scopeOf($target) : null;
        if ($scope !== null) {
            $key = 's:' . $scope;
            // @igor-ignore - Request-scoped memo, cleared by reset() (kernel.reset).
            $this->memo[$key] ??= $this->profiles->activeOf($scope) ?? false;
            $profile = $this->memo[$key];
            if ($profile instanceof StorageProfile) {
                return $this->usable($profile) ? $profile : null;
            }
        }

        return $this->instanceDefault();
    }

    /**
     * The usable instance profile; null = not set up or off.
     */
    public function instanceDefault(): ?StorageProfile
    {
        $profile = $this->activeOf(null);

        return $profile instanceof StorageProfile && $this->usable($profile) ? $profile : null;
    }

    /**
     * The active profile of an owner as the pages show it: for an environment-managed instance, its config row
     * (created on first use, so files can point at it); else the database row, if any.
     */
    public function activeOf(?string $scope): ?StorageProfile
    {
        if ($scope === null && $this->instanceProfile !== null) {
            return $this->configRow($this->instanceProfile);
        }
        if ($scope === null) {
            // @igor-ignore - Request-scoped memo, cleared by reset() (kernel.reset).
            $this->memo['instance'] ??= $this->profiles->activeOf(null) ?? $this->settingsRow() ?? false;
            $profile = $this->memo['instance'];

            return $profile instanceof StorageProfile ? $profile : null;
        }

        return $this->profiles->activeOf($scope);
    }

    /**
     * Whether the instance bucket is edited in the `storage` Instance Settings section (not managed by the
     * configuration).
     */
    public function settingsBacked(): bool
    {
        return $this->instanceProfile === null && $this->instanceSettings instanceof InstanceStorageSettings;
    }

    /**
     * The instance's active row for the `storage` Instance Settings section, created on first use once a provider is
     * selected there (files point at it, so it must exist before the first upload). Null when the section is not in
     * use or no provider is selected.
     */
    private function settingsRow(): ?StorageProfile
    {
        if (!$this->settingsBacked() || $this->instanceSettings?->provider() === null) {
            return null;
        }
        $profile = new StorageProfile();
        $profile->useInstanceSettings();

        return $this->created($profile);
    }

    /**
     * The row naming a config profile, created when missing; null when the name is not configured.
     */
    public function configRow(string $name): ?StorageProfile
    {
        if (!$this->config->has($name)) {
            return null;
        }
        $key = 'c:' . $name;
        if (!isset($this->memo[$key])) {
            $profile = $this->profiles->configRow($name);
            if (!$profile instanceof StorageProfile) {
                $profile = $this->created(new StorageProfile(null, StorageProfile::STATE_ACTIVE, $name));
            }
            // @igor-ignore - Request-scoped memo, cleared by reset() (kernel.reset).
            $this->memo[$key] = $profile;
        }
        $profile = $this->memo[$key];

        return $profile instanceof StorageProfile ? $profile : null;
    }

    /**
     * Writes a new row without flushing any entity manager (1.4, architecture bar rule 6: these rows are created on
     * the first upload, inside whatever the application left pending) and returns it managed (read back by uuid).
     * The rows created here carry no secret, so the DBAL insert stores nothing DoctrineEncrypt would encrypt.
     */
    private function created(StorageProfile $profile): StorageProfile
    {
        $this->entityManager->insert($profile);

        return $this->profiles->findOneByUuid($profile->getUuid()) ?? $profile;
    }

    /**
     * Enabled, complete and connectable.
     */
    public function usable(StorageProfile $profile): bool
    {
        return $profile->isEnabled() && $profile->isComplete() && $this->settingsOf($profile) instanceof S3Settings;
    }

    /**
     * Drops the memo (after a switch, and between worker requests).
     */
    public function reset(): void
    {
        $this->memo = [];
    }
}
