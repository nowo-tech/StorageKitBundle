# Templates

Pages of StorageKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.23+). With `web_ui.enabled: true` the bundle
has two page templates, the **Storage** page and the **Move** page, used for the instance (`web_ui.path_prefix`,
default `/admin/storage`) and for scopes (`scopes.path_prefix`, default `/storage/scopes/{scope}`).
`Nowo\StorageKitBundle\Ui\AdminPages` builds the UiKit view models (`Nowo\UiKitBundle\View\*`);
`Nowo\StorageKitBundle\Ui\StorageKitPages` resolves the layout (`web_ui.layout_template`, null =
`nowo_ui_kit.layouts.admin`) and the per-page overrides (`web_ui.templates.<page>`). Contract test:
`tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule).
Twig namespace: `@NowoStorageKitBundle` (views in `src/Resources/views/`).

The instance connection itself is edited on an Instance Settings page (the `storage` section,
`@NowoInstanceSettingsBundle/settings/section.html.twig`): StorageKit contributes the fields (labels in the
`NowoStorageKitBundle` domain, `visible-when` rows), the notices and two actions.

## Table of contents

- [Pages](#pages)
- [Actions](#actions)
- [Section actions](#section-actions)
- [Secrets in the pages](#secrets-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Translations](#translations)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoStorageKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoStorageKitBundle`)
and `layout: StorageKitPages::layout()`. Labels are `NowoStorageKitBundle` translation keys (`StorageKitPages::label()`).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Storage (`settings`) | `settings/edit.html.twig` | `page/form` | `FormPage` from `AdminPages::settingsPage()`: header `settings.title` / `settings.scope_title` with the on / off status; the FormKit `StorageProfileType` form (scope pages only) or a notice for a managed instance bucket; actions *Test connection*, *Edit connection* (settings-backed instance), *Move to another bucket*; aside from `AdminPages::settingsAside()` (usage, last test, configuration profile) | `nowo_ui_form_body` → block `nowo_storage_kit_settings_notice` (`managed`: `config` → `settings.env_managed`, `settings` → `settings.settings_managed`); `nowo_ui_form_aside` → `settings/_aside.html.twig` |
| Move (`move`) | `move/edit.html.twig` | `page/form` | `FormPage` from `AdminPages::movePage()`: header `move.title` with *Back to Storage* and the running status; the target form (read-only while a copy runs), *Save the new bucket*; actions Test, Start / Resume / Copy new files, Stop, Switch (confirm), Forget (confirm, danger); aside from `AdminPages::moveAside()` (current bucket, new bucket, copy, previous buckets) | `nowo_ui_form_aside` → block `nowo_storage_kit_move_progress` (UiKit `progress` partial while a copy runs) + `settings/_aside.html.twig` with the `move.notice` warning |

Other variables passed by the controllers: `scope` (null = the instance), `managed` (null, `config` or `settings`) and
`env_managed` (Storage page); `sync` (`Entity\StorageSync` or null) and `running` (Move page).

## Actions

POST actions carry a FormKit CSRF-only `FormView` and are rendered by UiKit's `_action_post` partial.

| Page | Action (`data-testid`) | Route | CSRF token id |
| --- | --- | --- | --- |
| Storage | *Test connection* (`storage-kit-test`) | `POST nowo_storage_kit_settings_test` / `nowo_storage_kit_scope_settings_test` | `nowo_storage_kit/test/instance` / `nowo_storage_kit/test/scope/<key>` |
| Storage | *Edit connection* (`storage-kit-edit-link`) | `GET nowo_instance_settings_section` (`section: storage`) | — |
| Storage | *Move to another bucket* (`storage-kit-move-link`) | `GET nowo_storage_kit_move` / `nowo_storage_kit_scope_move` | — |
| Storage | bucket form (scope) | `POST` same page | `nowo_storage_kit/settings/<owner>` |
| Move | target form | `POST` same page | `nowo_storage_kit/move/target/<owner>` |
| Move | `storage-kit-move-{test,sync,cancel,switch,discard}` | `POST nowo_storage_kit_move_action` / `nowo_storage_kit_scope_move_action` | `nowo_storage_kit/move/<action>/<owner>` |

`<owner>` is `instance` or `scope/<key>`. Handlers flash a translated outcome (UiKit `_flashes`) and redirect to the
page (path only).

## Section actions

| Section | Action (`data-testid`) | Route | CSRF token id |
| --- | --- | --- | --- |
| `storage` | *Test connection* (`storage-kit-section-test`) | `POST nowo_storage_kit_settings_test?from=settings` (redirects back to the section) | `nowo_storage_kit/test/instance` |
| `storage` | *Usage and move* (`storage-kit-section-overview`) | `GET nowo_storage_kit_settings` | — |

Only with `web_ui.enabled`. Notices: `section.notice.off`, `section.notice.incomplete`, `section.notice.test_failed`.

## Secrets in the pages

- The secret field is write-only (`always_empty`, `autocomplete="new-password"`); help says whether one is saved.
- View models and flashes never contain a secret, an access key value from the section, a signed URL or a provider
  message; test results are a step, a generic reason and an HTTP status class.
- Responses are `private, no-store`.

## Unique pages

- (none) — both pages extend the UiKit form scaffold. The upload and download endpoints are JSON / file responses, not
  pages.

## Per-page overrides

`web_ui.templates.settings` / `web_ui.templates.move` point at a host template that extends the bundle page and
overrides one block:

```twig
{# templates/admin/storage/settings.html.twig — web_ui.templates.settings #}
{% extends '@!NowoStorageKitBundle/settings/edit.html.twig' %}

{% block nowo_storage_kit_settings_notice %}
    {{ parent() }}
    <p>Ask the platform team before changing the bucket.</p>
{% endblock %}
```

## Overriding templates (REQ-TWIG-001)

See [USAGE.md — Overriding templates](USAGE.md#overriding-templates-req-twig-001) for the freeze rule, the recovery
procedure and the `<subpath>` table (`settings/edit.html.twig`, `move/edit.html.twig`, `settings/_aside.html.twig`).
Prefer `nowo_ui_kit.layouts.admin`, the UiKit theme and `web_ui.templates.<page>` over full-file forks.

## Bundle partials and assets

- `settings/_aside.html.twig`: the aside cards of both pages (UiKit `section` / `card*` slots, `detail_list` partial,
  optional notice in a `flash_<tone>` slot).
- No CSS / JS of its own (no `nowo_ui_styles` / `nowo_ui_scripts` override, no asset package). The provider-dependent
  rows rely on the UiKit `visible-when` Stimulus peer, registered by the host
  ([INSTALLATION](INSTALLATION.md#visible-when-stimulus-peer)).

## Translations

`NowoStorageKitBundle` (`settings.*`, `status.*`, `delivery.*`, `usage.*`, `test.*`, `probe.*`, `profile.*`, `move.*`,
`upload.*`, `provider.*`, `section.*`) in `en`, `es`, `fr`, `de`, `it`, `pt`, `nl`. The contract test loads the bundle
catalogues with the strict missing-translation rule.

## Allowed exceptions

- (none)
