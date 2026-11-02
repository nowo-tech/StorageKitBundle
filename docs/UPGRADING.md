# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app mailer (Beacon-style)](#migrating-from-an-in-app-mailer-beacon-style)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

- `nowo-tech/controller-kit-bundle` is now required at `^2.3.1` (`composer update nowo-tech/controller-kit-bundle`).
  Exported cells with a formula trigger after an embedded `;`, `,`, tab, `|` or line break now read `Hi; =x` instead
  of `Hi;=x`, and the response sends `X-Content-Type-Options: nosniff`.
- **Notable behaviour change:** `web_ui.export.max_rows` accepts at most **100 000** (was 1 000 000). A configuration
  above it no longer compiles; lower it, or narrow the filters of the export.
- New optional `web_ui.export.body_batch_bytes` (default 8 MiB, 1 MiB … 64 MiB): approximate body bytes loaded per
  query by the export with bodies. Nothing to change unless you want a different memory bound.

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

No code change is required. Review two defaults:

- The log list now offers **"Export CSV"** to whoever can open it (`web_ui.export.enabled: true`). To keep it for a
  narrower role, set `web_ui.export.access_roles: [ROLE_…]`; to remove it, `web_ui.export.enabled: false`. Bodies are
  never exported unless you also set `web_ui.export.include_bodies: true` (needs `log.store_bodies`).
- New requirements: `nowo-tech/ui-kit-bundle` `^2.20` and `nowo-tech/controller-kit-bundle` `^2.3` (only its
  `Http\CsvStreamedResponse` / `CsvSafeWriter` classes are used; registering `NowoControllerKitBundle` is not needed).
- A template override of `log/index.html.twig` keeps working; the table headers now sort (UiKit `sorting`) and the
  filter form carries two hidden fields (`sort`, `dir`), rendered by `form_end` if your override does not.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+, `symfony/mailer` with `framework.mailer` configured.
- `nowo-tech/instance-settings-bundle` ^1.0 registered (and DoctrineEncrypt configured for its secrets): the container
  refuses to compile without it.

### Enable and configure

1. `composer require nowo-tech/storage-kit-bundle` (Flex registers the bundle and copies the recipe).
2. Import the routes (opt-in) — `nowo_storage_kit` and `nowo_instance_settings` loaders.
3. Generate a migration for `storage_kit_sent_mail` / `storage_kit_sent_mail_body` (with your `doctrine.table_prefix`).
4. Add `access_control` rules for `/admin/mail` and `/admin/settings`.
5. Schedule `nowo:storage-kit:log:purge`.

### Migrating from an in-app mailer (Beacon-style)

Apps that kept the mailer DSN, sender and Resend key in their own settings entity and wrapped the mailer themselves:

1. **Move the values** into the Instance Settings rows (once, in a migration or a command):
   `$settings->save('mailer', ['dsn' => $old->getMailerDsn(), 'from' => $old->getMailerFrom()])` and, with
   `resend.enabled: true`, `$settings->save('resend', ['enabled' => $old->isResendEnabled(), 'api_key' => $old->getResendApiKey(), 'from' => $old->getResendFrom()])`.
   Then drop the old columns.
2. **Delete the wrappers**: the configured `MailerInterface` alias, the transport used by
   `mailer.messenger.message_handler`, the DSN validator / audit / sender helpers, the Resend checker, the settings
   controllers, forms and templates — StorageKit's decorators cover both the sync and the Messenger path.
3. **Callers** of `sendNow()` / `sendSample()` use `Nowo\StorageKitBundle\Mailer\RuntimeTransport::send()` (synchronous) or
   `TestMailSender`; `getFromAddress()` → `MailSettings::from()`; `isMagicLoginAvailable()` / `canDeliver()` →
   `OutboundMailReadiness::isOutboundMailReady()`; `MailerDsnAudit::redact()` keeps its shape (`scheme`, `host`, plus
   `port`).
4. **Log**: the old e-mail log table can be dropped or migrated into `storage_kit_sent_mail` (same columns, `template` →
   `tag`); the tag header is `X-Mail-Kit-Tag` (`MailHeaders::tag()`), the redaction header `X-Mail-Kit-Redact`
   (`MailHeaders::redact()`). Bodies are no longer stored unless `log.store_bodies: true`.
5. **Test environment**: StorageKit does not swap SMTP for `null://` in `APP_ENV=test`; store no DSN in test databases (the
   framework test DSN is used) or set `mailer.decorate: false` in `when@test`.

### Template contract

First release: pages `log/index.html.twig` (list scaffold, block `nowo_ui_list_row`) and `log/show.html.twig` (show
scaffold, blocks `nowo_ui_show_after` / `nowo_storage_kit_text_body`), view models from `Ui\AdminPages`. Later changes to
blocks, view-model fields or theme slots will be listed here under "Template contract".

### Breaking changes

None (initial release).
