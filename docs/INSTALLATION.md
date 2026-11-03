# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup (without Flex)](#manual-setup-without-flex)
- [Routes](#routes)
- [visible-when Stimulus peer](#visible-when-stimulus-peer)
- [Database schema](#database-schema)
- [Firewall and access control](#firewall-and-access-control)
- [Scheduled purge and Messenger](#scheduled-purge-and-messenger)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6` with `ext-gd` (WebP support for WebP uploads); Symfony **7.4**, **8.0** or **8.1** (8.x requires
  PHP 8.4+).
- Doctrine ORM 3.3+ with DoctrineBundle; `nowo-tech/doctrine-encrypt-bundle` ^2.10 **registered and configured** (the
  container refuses to compile without it: scope / move-target bucket secrets are encrypted at rest).
- `nowo-tech/instance-settings-bundle` **^1.3.1** registered when the instance bucket is the `storage` section
  (default, `instance_profile: null`).
- `nowo-tech/ui-kit-bundle` **^2.23** and `nowo-tech/form-kit-bundle` ^2.17 (admin pages, bucket form, `visible-when`
  peer).
- `async-aws/s3` ^2.8 || ^3.0 with `league/flysystem` / `league/flysystem-async-aws-s3` ^3.29 (installed as
  dependencies), `nowo-tech/outbound-url-guard-bundle` ^1.3 (used as a library by the SSRF guard), `twig/extra-bundle`.
- FrameworkBundle with CSRF protection (form CSRF when the pages are on), RateLimiter over `cache.app`, HttpClient,
  TwigBundle; SecurityBundle for the admin pages.
- Recommended: `framework.lock` configured (`lock.factory`: a lock per running sync, on top of the atomic database
  claim).
- Optional: `symfony/messenger` (background bucket moves), `nowo-tech/http-hardening-bundle` (CSP adapter), `nowo-tech/milkdown-editor-bundle` (Markdown editor upload hook).
- An S3-compatible bucket (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, Scaleway, RustFS, MinIO…). There is no local
  disk provider.

## Install with Composer

```bash
composer require nowo-tech/storage-kit-bundle
```

## Symfony Flex recipe

The recipe registers `Nowo\StorageKitBundle\NowoStorageKitBundle` and adds:

- `config/packages/nowo_storage_kit.yaml` — safe defaults (instance bucket in the `storage` section, `ROLE_ADMIN` for
  the instance pages, `ROLE_USER` for uploads, private endpoints refused);
- `config/routes/nowo_storage_kit.yaml` — the opt-in route import ([Routes](#routes)).

You still import the Instance Settings routes, register the `visible-when` peer, generate the migration and add the
`access_control` rules (below).

## Manual setup (without Flex)

1. Register the bundles in `config/bundles.php` (dependencies first):

   ```php
   return [
       // …
       Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class         => ['all' => true],
       Symfony\Bundle\SecurityBundle\SecurityBundle::class          => ['all' => true],
       Twig\Extra\TwigExtraBundle\TwigExtraBundle::class            => ['all' => true],
       Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class  => ['all' => true],
       Nowo\UiKitBundle\NowoUiKitBundle::class                      => ['all' => true],
       Nowo\FormKitBundle\NowoFormKitBundle::class                  => ['all' => true],
       Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
       Nowo\StorageKitBundle\NowoStorageKitBundle::class            => ['all' => true],
   ];
   ```

2. Configure DoctrineEncrypt (key outside the repository) — see its documentation.
3. Create `config/packages/nowo_storage_kit.yaml` (optional: defaults are safe, see [CONFIGURATION.md](CONFIGURATION.md)).
4. Import the routes.

## Routes

The bundle exposes **no route** unless the host imports its loader (REQ-SF-004 rule 7):

```yaml
# config/routes/nowo_storage_kit.yaml
nowo_storage_kit:
    resource: .
    type: nowo_storage_kit
```

It mounts, depending on the configuration:

| Route | Path (defaults) | Method | Condition |
| --- | --- | --- | --- |
| `nowo_storage_kit_settings` | `/admin/storage` | GET, POST | `web_ui.enabled` |
| `nowo_storage_kit_settings_test` | `/admin/storage/test` | POST | `web_ui.enabled` |
| `nowo_storage_kit_move` | `/admin/storage/move` | GET, POST | `web_ui.enabled` |
| `nowo_storage_kit_move_action` | `/admin/storage/move/{test\|sync\|cancel\|switch\|discard}` | POST | `web_ui.enabled` |
| `nowo_storage_kit_scope_settings` | `/storage/scopes/{scope}` | GET, POST | `web_ui.enabled` + `scopes.enabled` |
| `nowo_storage_kit_scope_settings_test` | `/storage/scopes/{scope}/test` | POST | `web_ui.enabled` + `scopes.enabled` |
| `nowo_storage_kit_scope_move` | `/storage/scopes/{scope}/move` | GET, POST | `web_ui.enabled` + `scopes.enabled` |
| `nowo_storage_kit_scope_move_action` | `/storage/scopes/{scope}/move/{action}` | POST | `web_ui.enabled` + `scopes.enabled` |
| `nowo_storage_kit_upload` | `/storage/upload/{type}/{id}` | POST | `upload.enabled` |
| `nowo_storage_kit_file` | `/storage/files/{uuid}/{original\|preview\|thumb}` | GET | `upload.enabled` |

With the default `instance_profile: null` the instance connection is edited on the Instance Settings section page, so
also import the Instance Settings routes (the instance Storage page links to `nowo_instance_settings_section`; without
the route the *Edit connection* link is left out):

```yaml
# config/routes/nowo_instance_settings.yaml
nowo_instance_settings:
    resource: .
    type: nowo_instance_settings
```

## visible-when Stimulus peer

The `storage` section and the bucket form show the endpoint, public endpoint and path-style rows only for an
S3-compatible provider. The rows carry UiKit ^2.23 `visible-when` attributes; register the peer in your Stimulus app
or every row stays visible (saving still works: the submitted provider decides what is validated and stored):

```js
// assets/bootstrap.js (or your Stimulus entry)
import VisibleWhenController from '…/vendor/nowo-tech/ui-kit-bundle/src/Resources/assets/stimulus-peers/visible_when_controller';
app.register('visible-when', VisibleWhenController);
```

Keep the UiKit stylesheet (`[data-nowo-ui-visible-when-field][hidden] { display: none !important }`). A host-overridden
section template that keeps `page.attributes` on the scaffold root needs no change; a fully custom one must put
`data-controller="visible-when"` on an ancestor of the form.

## Database schema

The four entities are mapped automatically (attribute mapping, alias `NowoStorageKit`). Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Tables (with `doctrine.table_prefix`): `storage_kit_profile`, `storage_kit_file`, `storage_kit_file_reference`,
`storage_kit_sync`. Instance Settings keeps the `storage` section in its own table.

## Firewall and access control

The instance pages check `security.access_roles` (default `ROLE_ADMIN`) themselves, before the controller; add the
host rules as a second layer:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/storage, roles: ROLE_ADMIN }
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

The upload endpoint needs a signed-in user with one of `upload.access_roles` (default `ROLE_USER`) behind a firewall;
downloads are decided by your file contexts (anonymous viewers are asked too). Scope pages answer 404 to anyone the
scope access checker refuses.

## Scheduled purge and Messenger

```cron
# Orphans older than purge.retention_days (default 7) — daily
15 3 * * * php /app/bin/console nowo:storage-kit:purge
```

Route the background move job to a worker transport (otherwise run `nowo:storage-kit:sync` by hand):

```yaml
framework:
    messenger:
        routing:
            Nowo\StorageKitBundle\Message\RunStorageSyncMessage: async
```

## Verify

```bash
php bin/console debug:router | grep nowo_storage_kit
php bin/console debug:config nowo_storage_kit
php bin/console nowo:storage-kit:purge --days=7
```

Open **Settings → Storage** (`/admin/settings/sections/storage` with the Instance Settings defaults), choose a provider,
fill in the bucket and press **Test connection**: the flash says *The connection works: an object was written, read,
signed and deleted.* Then implement a file context ([USAGE](USAGE.md#file-contexts-who-may-write-and-read)) to enable uploads in your texts.
