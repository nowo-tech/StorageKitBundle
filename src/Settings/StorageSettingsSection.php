<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Settings;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Form\ProfileConstraints;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The `storage` Instance Settings section: the instance's bucket connection. `provider` decides which fields are
 * shown and saved (InstanceSettings 1.3 `visible_when`): nothing but the provider while it is empty (uploads off),
 * region / bucket / prefix / access key / secret for both providers, endpoint / public endpoint / path style only for
 * an S3-compatible one. The secret is an Instance Settings secret (encrypted at rest, never rendered back, blank
 * keeps). The endpoint goes through the same SSRF guard as the scope buckets (unless `endpoints.guard_instance` is
 * false). With the admin pages on, the section page also shows *Test connection* (POST + CSRF) and a link to the
 * storage overview (usage, test result, move).
 *
 * Registered only when the instance bucket is not managed by the configuration (`instance_profile` null).
 */
final class StorageSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface, SettingsSectionNoticesInterface
{
    /** Section name (key prefix `storage.`, URL segment, export key). */
    public const NAME = 'storage';

    /** Tag priority (after the host's own sections). */
    public const PRIORITY = -10;

    /** Fields shown for every provider. */
    private const COMMON = [StorageProvider::AWS_S3, StorageProvider::S3_COMPATIBLE];

    /** Fields shown for an S3-compatible provider only. */
    private const ENDPOINT = [StorageProvider::S3_COMPATIBLE];

    /**
     * @param EndpointGuard $guard SSRF guard of the endpoint field
     * @param InstanceStorageSettings $instance stored connection (notices)
     * @param StorageProfiles|null $profiles profile resolver (last test result notice)
     * @param UrlGeneratorInterface|null $urls router (actions; null = no admin pages)
     * @param CsrfOnlyFormFactory|null $csrfForms FormKit CSRF-only form factory (test action)
     * @param bool $restricted whether the endpoint goes through the SSRF guard
     */
    public function __construct(
        private readonly EndpointGuard $guard,
        private readonly InstanceStorageSettings $instance,
        private readonly ?StorageProfiles $profiles = null,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
        private readonly bool $restricted = true,
    ) {
    }

    /**
     * `storage`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Section title key.
     */
    public function getLabel(): string
    {
        return 'section.label';
    }

    /**
     * `NowoStorageKitBundle`.
     */
    public function getTranslationDomain(): string
    {
        return NowoStorageKitBundle::TRANSLATION_DOMAIN;
    }

    /**
     * Intro key.
     */
    public function getIntro(): string
    {
        return 'section.intro';
    }

    /**
     * Fields in form order.
     *
     * @return iterable<SettingField>
     */
    public function getFields(): iterable
    {
        yield SettingField::choice('provider', StorageProvider::choices(), null, 'profile.field.provider', 'section.help.provider');
        yield SettingField::url('endpoint', null, 'profile.field.endpoint', $this->restricted ? 'profile.help.endpoint_restricted' : 'profile.help.endpoint', true, ProfileConstraints::endpoint($this->guard, $this->restricted))
            ->withVisibleWhen('provider', self::ENDPOINT);
        yield SettingField::url('public_endpoint', null, 'profile.field.public_endpoint', 'profile.help.public_endpoint', constraints: ProfileConstraints::endpoint(null, false))
            ->withVisibleWhen('provider', self::ENDPOINT);
        yield SettingField::string('region', S3Settings::DEFAULT_REGION, 'profile.field.region', constraints: ProfileConstraints::region())
            ->withVisibleWhen('provider', self::COMMON);
        yield SettingField::string('bucket', null, 'profile.field.bucket', required: true, constraints: ProfileConstraints::bucket())
            ->withVisibleWhen('provider', self::COMMON);
        yield SettingField::string('prefix', null, 'profile.field.prefix', 'profile.help.prefix', constraints: ProfileConstraints::prefix())
            ->withVisibleWhen('provider', self::COMMON);
        yield SettingField::string('access_key', null, 'profile.field.access_key', required: true, constraints: ProfileConstraints::accessKey())
            ->withVisibleWhen('provider', self::COMMON);
        yield SettingField::secret('secret', 'profile.field.secret', 'section.help.secret', true, ProfileConstraints::secret())
            ->withVisibleWhen('provider', self::COMMON);
        yield SettingField::bool('path_style', false, 'profile.field.path_style', 'profile.help.path_style')
            ->withVisibleWhen('provider', self::ENDPOINT);
    }

    /**
     * *Test connection* (POST + CSRF, back to this page) and *Storage overview*, when the admin pages are on.
     *
     * @param SectionActionContext $context section, request and user of the page being rendered (unused: the page itself is access-checked)
     *
     * @return iterable<Action>
     */
    public function getActions(SectionActionContext $context): iterable
    {
        if (!$this->urls instanceof UrlGeneratorInterface || !$this->csrfForms instanceof CsrfOnlyFormFactory) {
            return [];
        }
        $test = $this->urls->generate(StorageSettingsController::ROUTE_TEST, ['from' => StorageSettingsController::FROM_SETTINGS]);

        return [
            new Action(
                'custom',
                new Label('settings.action.test', NowoStorageKitBundle::TRANSLATION_DOMAIN),
                $test,
                method: 'POST',
                variant: 'secondary',
                attributes: ['data-testid' => 'storage-kit-section-test'],
                form: $this->csrfForms->createNamed($test, StorageSettingsController::testTokenId(null))->createView(),
            ),
            new Action(
                'custom',
                new Label('section.action.overview', NowoStorageKitBundle::TRANSLATION_DOMAIN),
                $this->urls->generate(StorageSettingsController::ROUTE),
                variant: 'secondary',
                attributes: ['data-testid' => 'storage-kit-section-overview'],
            ),
        ];
    }

    /**
     * "Uploads are off" while no provider is selected, "incomplete" when a selected provider cannot connect with the
     * stored fields, and the failed result of the last connection test. Field names only, never values.
     *
     * @param SectionActionContext $context section, request and user of the page being rendered (unused)
     *
     * @return iterable<string>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        if ($this->instance->provider() === null) {
            return ['section.notice.off'];
        }
        if (!$this->instance->settings() instanceof S3Settings) {
            return ['section.notice.incomplete'];
        }
        $active = $this->profiles?->activeOf(null);

        return $active instanceof StorageProfile && $active->isSettingsBacked() && $active->getTestOk() === false ? ['section.notice.test_failed'] : [];
    }
}
