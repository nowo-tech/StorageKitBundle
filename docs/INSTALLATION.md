# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup (without Flex)](#manual-setup-without-flex)
- [Framework mailer](#framework-mailer)
- [Database schema](#database-schema)
- [Firewall and access control](#firewall-and-access-control)
- [Twig Extra and assets](#twig-extra-and-assets)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6`; Symfony **7.4**, **8.0** or **8.1** (8.x requires PHP 8.4+)
- `symfony/mailer` with `framework.mailer` configured (the fallback transport)
- `nowo-tech/instance-settings-bundle` ^1.0 — installed as a dependency; it needs `nowo-tech/doctrine-encrypt-bundle`
  configured (the DSN and the API key are encrypted secrets) and brings UiKit / FormKit
- Doctrine ORM 3.3+ with DoctrineBundle, `nowo-tech/outbound-url-guard-bundle` ^1.3 (host rules, no bundle registration
  needed), `twig/extra-bundle` + `twig/string-extra` — installed as dependencies
- Optional: `symfony/resend-mailer` (Resend section), `nowo-tech/auth-kit-bundle` (readiness adapter),
  `symfony/messenger` (async mail)

## Install with Composer

```bash
composer require nowo-tech/storage-kit-bundle
```

## Symfony Flex recipe

The recipe lives in this repository under [`.symfony/recipe/nowo-tech/storage-kit-bundle/1.0/`](../.symfony/recipe/nowo-tech/storage-kit-bundle/1.0/) and installs:

- `config/packages/nowo_storage_kit.yaml` — safe defaults (`ROLE_ADMIN`, `allow_unauthenticated: false`, no bodies, 30-day
  retention) with commented examples;
- `config/routes/nowo_storage_kit.yaml` — the route loader import (`/admin/mail`).

Install Instance Settings' recipe too (it adds its own routes and `access_control` reminder).

## Manual setup (without Flex)

1. Register the bundles (Instance Settings and its dependencies **before** StorageKit):

   ```php
   // config/bundles.php
   return [
       // …
       Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
       Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class => ['all' => true],
       Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
       Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
       Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
       Nowo\StorageKitBundle\NowoStorageKitBundle::class => ['all' => true],
   ];
   ```

2. Import the routes (opt-in; never picked up by `routing.controllers`):

   ```yaml
   # config/routes/nowo_storage_kit.yaml
   nowo_storage_kit:
       resource: .
       type: nowo_storage_kit

   # config/routes/nowo_instance_settings.yaml (the settings pages that carry the mailer section)
   nowo_instance_settings:
       resource: .
       type: nowo_instance_settings
   ```

3. Configure the bundle (optional — defaults are safe), see [CONFIGURATION.md](CONFIGURATION.md).

4. Make sure `framework.csrf_protection` and `framework.form.csrf_protection` are on (default in Symfony apps).

## Framework mailer

Keep a framework DSN as the bootstrap fallback; StorageKit replaces it at runtime once a DSN is stored in the settings:

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'   # e.g. null://null until an operator stores a DSN
```

## Database schema

The Doctrine mapping of the log entities is registered automatically. Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Firewall and access control

The UI is private (`ROLE_ADMIN`) but the bundle trusts the user only when a firewall matched the request: put the
prefixes behind your firewall and add `access_control` rules.

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/mail, roles: ROLE_ADMIN }
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

## Twig Extra and assets

The bundle requires `twig/extra-bundle` and `twig/string-extra` (REQ-TWIG-004): enable `TwigExtraBundle` (Flex does).
The pages use UiKit's stylesheet and scripts through the UiKit layout; run `php bin/console assets:install` once after
install / upgrade. The bundle ships no assets of its own.

## Verify

```bash
php bin/console debug:router | grep nowo_storage_kit
php bin/console nowo:storage-kit:send-test you@example.com
```

Open `/admin/settings/sections/mailer`, store a DSN and click **Send a test e-mail**; the message appears in
`/admin/mail/log`. Schedule `php bin/console nowo:storage-kit:log:purge` daily.
