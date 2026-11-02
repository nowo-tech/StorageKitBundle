<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Ui;

use DateTimeInterface;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\PageStatus;
use Symfony\Component\Form\FormView;

/**
 * Builds the UiKit view models of the Storage page (`settings/edit.html.twig`) and the Move page
 * (`move/edit.html.twig`), both on the UiKit form scaffold (REQ-UI-003): header with the status, the FormKit form, the
 * POST actions (CSRF-only forms) and the aside sections (usage, last test, sync progress, retired buckets). Plain data
 * in, immutable view models out: no request state is kept (worker safe). Secrets are never part of a view model.
 */
final readonly class AdminPages
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageKitPages $pages,
        private StorageProfiles $profiles,
    ) {
    }

    /**
     * The Storage page.
     *
     * @param array{files: int, bytes: int} $usage
     * @param list<Action> $actions secondary actions (test, move)
     */
    public function settingsPage(?string $scope, ?FormView $form, StorageProfile $profile, bool $envManaged, array $usage, array $actions): FormPage
    {
        $usable = $profile->getId() !== null && $this->profiles->usable($profile);
        $title  = $scope === null ? StorageKitPages::label('settings.title') : StorageKitPages::label('settings.scope_title', ['%scope%' => $scope]);

        return new FormPage(
            new PageHeader(
                $title,
                StorageKitPages::label($envManaged ? 'settings.intro_env' : ($scope === null ? 'settings.intro' : 'settings.intro_scope')),
                [],
                [new Crumb($title)],
                status: new PageStatus(StorageKitPages::label($usable ? 'status.on' : 'status.off'), $usable ? 'success' : 'neutral'),
            ),
            $form,
            StorageKitPages::label('settings.action.save'),
            secondaryActions: $actions,
            bundle: StorageKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'storage-kit-settings'],
        );
    }

    /**
     * Aside of the Storage page: usage, last test, environment-managed bucket.
     *
     * @param array{files: int, bytes: int} $usage
     *
     * @return list<DetailSection>
     */
    public function settingsAside(StorageProfile $profile, bool $envManaged, array $usage): array
    {
        $sections = [$this->usageSection($usage), $this->testSection($profile)];
        if ($envManaged) {
            $sections[] = $this->configSection($profile);
        }

        return $sections;
    }

    /**
     * The Move page.
     *
     * @param list<Action> $actions secondary actions (test, sync, cancel, switch, discard)
     */
    public function movePage(?string $scope, ?FormView $form, bool $running, array $actions, string $settingsHref): FormPage
    {
        $title    = StorageKitPages::label('move.title');
        $settings = $scope === null ? StorageKitPages::label('settings.title') : StorageKitPages::label('settings.scope_title', ['%scope%' => $scope]);

        return new FormPage(
            new PageHeader(
                $title,
                StorageKitPages::label('move.intro'),
                [new Action('prev', StorageKitPages::label('move.action.back'), href: $settingsHref, attributes: ['data-testid' => 'storage-kit-back'])],
                [new Crumb($settings, $settingsHref), new Crumb($title)],
                status: $running ? new PageStatus(StorageKitPages::label('move.status.running'), 'info') : null,
            ),
            $form,
            StorageKitPages::label('move.action.save_target'),
            secondaryActions: $actions,
            bundle: StorageKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'storage-kit-move'],
            secondaryActionsInHeader: false,
        );
    }

    /**
     * Aside of the Move page: the current bucket, the target, the sync and the retired buckets.
     *
     * @param array{files: int, bytes: int} $usage
     * @param list<StorageProfile> $retired
     *
     * @return list<DetailSection>
     */
    public function moveAside(?StorageProfile $active, StorageProfile $target, ?StorageSync $sync, array $usage, array $retired, bool $background): array
    {
        $sections = [
            new DetailSection(StorageKitPages::label('move.section.current'), [
                new DetailItem(StorageKitPages::label('move.field.bucket'), $active instanceof StorageProfile ? $this->bucketOf($active) : null),
                new DetailItem(StorageKitPages::label('move.field.profile'), $active?->getUuid()),
                new DetailItem(StorageKitPages::label('usage.files'), $usage['files']),
                new DetailItem(StorageKitPages::label('usage.bytes'), Bytes::format($usage['bytes'])),
            ], 'storage-kit-current'),
            new DetailSection(StorageKitPages::label('move.section.target'), [
                new DetailItem(StorageKitPages::label('move.field.bucket'), $target->getId() === null ? null : $this->bucketOf($target)),
                new DetailItem(StorageKitPages::label('move.field.profile'), $target->getId() === null ? null : $target->getUuid()),
                ...$this->testSection($target)->items,
            ], 'storage-kit-target'),
        ];
        $sections[] = new DetailSection(StorageKitPages::label('move.section.sync'), $sync instanceof StorageSync
            ? [
                new DetailItem(StorageKitPages::label('move.field.status'), StorageKitPages::label('move.sync.' . $sync->getStatus()), badge: $this->badge($sync)),
                new DetailItem(StorageKitPages::label('move.field.progress'), StorageKitPages::label('move.sync.progress', ['%done%' => $sync->getProcessed(), '%total%' => $sync->getTotal(), '%percent%' => $sync->percent()])),
                new DetailItem(StorageKitPages::label('move.field.objects'), StorageKitPages::label('move.sync.objects', ['%copied%' => $sync->getCopiedObjects(), '%skipped%' => $sync->getSkippedObjects()])),
                new DetailItem(StorageKitPages::label('move.field.error'), $sync->getError()),
                new DetailItem(StorageKitPages::label('move.field.updated'), $sync->getUpdatedAt()->format(DateTimeInterface::ATOM)),
            ]
            : [new DetailItem(StorageKitPages::label('move.field.status'), StorageKitPages::label($background ? 'move.sync.none' : 'move.sync.none_cli'))], 'storage-kit-sync');
        if ($retired !== []) {
            $items = [];
            foreach ($retired as $profile) {
                $items[] = new DetailItem($this->bucketOf($profile), $profile->getUuid());
            }
            $sections[] = new DetailSection(StorageKitPages::label('move.section.retired'), $items, 'storage-kit-retired');
        }

        return $sections;
    }

    /**
     * A POST action carrying a CSRF-only form.
     */
    public static function postAction(string $label, string $href, FormView $form, string $testId, string $variant = 'secondary', bool $disabled = false, ?string $confirm = null): Action
    {
        return new Action(
            'custom',
            StorageKitPages::label($label),
            href: $href,
            method: 'POST',
            confirm: $confirm === null ? null : new Confirm(StorageKitPages::label($confirm)),
            variant: $variant,
            attributes: ['data-testid' => $testId],
            form: $form,
            disabled: $disabled,
        );
    }

    /**
     * @param array{files: int, bytes: int} $usage
     */
    private function usageSection(array $usage): DetailSection
    {
        return new DetailSection(StorageKitPages::label('usage.title'), [
            new DetailItem(StorageKitPages::label('usage.files'), $usage['files']),
            new DetailItem(StorageKitPages::label('usage.bytes'), Bytes::format($usage['bytes'])),
        ], 'storage-kit-usage');
    }

    private function testSection(StorageProfile $profile): DetailSection
    {
        $ok = $profile->getTestOk();

        return new DetailSection(StorageKitPages::label('test.title'), [
            new DetailItem(StorageKitPages::label('test.last'), $profile->getTestedAt()?->format(DateTimeInterface::ATOM)),
            new DetailItem(
                StorageKitPages::label('test.result'),
                $ok === null ? null : StorageKitPages::label($ok ? ($profile->isTestPublicRead() ? 'test.public' : 'test.ok') : 'test.failed'),
                badge: $ok === null ? null : ($ok ? ($profile->isTestPublicRead() ? 'warning' : 'success') : 'danger'),
            ),
        ], 'storage-kit-last-test');
    }

    private function configSection(StorageProfile $profile): DetailSection
    {
        $settings = $this->profiles->settingsOf($profile);

        return new DetailSection(StorageKitPages::label('settings.section.config'), [
            new DetailItem(StorageKitPages::label('settings.field.config_profile'), $profile->getConfigName()),
            new DetailItem(StorageKitPages::label('move.field.bucket'), $settings instanceof S3Settings ? $settings->bucket : StorageKitPages::label('settings.config_invalid')),
            new DetailItem(StorageKitPages::label('settings.field.delivery'), StorageKitPages::label('delivery.' . $this->profiles->deliveryOf($profile))),
        ], 'storage-kit-config');
    }

    private function bucketOf(StorageProfile $profile): string
    {
        $name = $profile->getConfigName();
        if ($name !== null) {
            return $this->profiles->settingsOf($profile)->bucket ?? $name;
        }

        return $profile->getBucket() . ($profile->getPrefix() === '' ? '' : '/' . $profile->getPrefix());
    }

    private function badge(StorageSync $sync): string
    {
        return match ($sync->getStatus()) {
            StorageSync::COMPLETED => 'success',
            StorageSync::FAILED    => 'danger',
            StorageSync::CANCELLED => 'neutral',
            default                => 'info',
        };
    }
}
