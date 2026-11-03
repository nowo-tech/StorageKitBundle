<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Ui;

use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\UiKitBundle\View\Label;

use function is_string;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): the layout the pages extend (`web_ui.layout_template`,
 * null = `nowo_ui_kit.layouts.admin`) and the per-page template overrides (`web_ui.templates.<page>`). Configuration
 * only: safe in worker mode.
 */
final readonly class StorageKitPages
{
    /** Bundle name of the view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoStorageKitBundle`). */
    public const BUNDLE = NowoStorageKitBundle::TRANSLATION_DOMAIN;

    /** Translation domain of the bundle labels (REQ-I18N-003). */
    public const DOMAIN = NowoStorageKitBundle::TRANSLATION_DOMAIN;

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'settings' => '@NowoStorageKitBundle/settings/edit.html.twig',
        'move'     => '@NowoStorageKitBundle/move/edit.html.twig',
    ];

    /**
     * @param string|null $layoutTemplate `web_ui.layout_template` (null = UiKit admin layout)
     * @param array<string, string> $templates `web_ui.templates` (page id => template)
     */
    public function __construct(
        private ?string $layoutTemplate = null,
        private array $templates = [],
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, int|string> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$page] ?? $page;
    }

    /**
     * Layout the scaffolds extend (`page.layout`): null = `nowo_ui_layout.admin`.
     */
    public function layout(): ?string
    {
        return $this->layoutTemplate === '' ? null : $this->layoutTemplate;
    }
}
