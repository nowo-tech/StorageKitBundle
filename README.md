# Storage Kit Bundle

[![CI](https://github.com/nowo-tech/StorageKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/StorageKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/storage-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/storage-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/storage-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/storage-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/StorageKitBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/StorageKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/storage-kit-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/StorageKitBundle) so more developers can find it.

**Storage Kit Bundle** — the mailer DSN, sender and test e-mail an operator edits without a deploy. It contributes a **`mailer`** (and optional **`resend`**) section to [Instance Settings](https://github.com/nowo-tech/InstanceSettingsBundle) — DSN encrypted at rest, sender address and name, Reply-To, test recipient — and decorates the Symfony mailer so **every send resolves the DSN at runtime** (framework `MAILER_DSN` as fallback), also for messages queued to Messenger. DSNs are validated (no `sendmail` / `native` / `null`, SSRF-safe hosts through [OutboundUrlGuard](https://github.com/nowo-tech/OutboundUrlGuard) rules), senders must be on a public domain, a "send a test e-mail" action and CLI answer with the transport's own error, a Resend key check calls the API with a deadline, and a **sent-mail log** (envelope only by default, retention purge, right-to-erasure command) has a UiKit admin page. `isOutboundMailReady()` gates AuthKit magic-login / password-reset. Tested on Symfony **7.4**, **8.0** and **8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+).

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

The transport built from the stored DSN is cached per DSN and dropped on `kernel.reset` and on `SettingsChangedEvent`; the DSN itself is read on every send, so a change saved in one worker is used by every worker on its next message. See [docs/FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md).

## Screenshots

<table>
  <tr>
    <td align="center" width="50%">
      <img src="docs/images/demo/mailer-settings.webp" alt="Mailer settings section: encrypted DSN shown only as saved, sender fields and the Send a test e-mail action with its success message" />
      <br /><sub>Mailer section in Instance Settings: encrypted DSN, sender and a test e-mail that reports the transport answer</sub>
    </td>
    <td align="center" width="50%">
      <img src="docs/images/demo/sent-mail-log.webp" alt="Sent-mail log: filters, status badges, subjects, recipients, tags and transports of the last messages" />
      <br /><sub>Sent-mail log: status, recipients, tag and transport per message (no bodies by default)</sub>
    </td>
  </tr>
</table>

## Features

- **Runtime DSN** — `RuntimeTransport` decorates `mailer.transports` (used by the framework mailer **and** the Messenger `SendEmailMessage` handler): the DSN comes from the `mailer.dsn` setting (or the Resend key) on every send, the framework transport (`MAILER_DSN`) is the fallback, a message with an `X-Transport` header keeps its named transport. The built transport is cached per DSN and reset on `kernel.reset` / `SettingsChangedEvent` (worker safe). Replace the source with your own `MailerDsnProviderInterface`.
- **`MailerInterface` decorator** — fills the runtime **From** (`mailer.from` / `mailer.from_name`) and **Reply-To** when a message has none, before the framework mailer builds the envelope or queues it.
- **Settings sections** — `mailer`: DSN (encrypted secret), sender address and name, Reply-To, test recipient; optional `resend`: switch, API key (encrypted), sender. Validated on save and import; never rendered back.
- **DSN validation** — allow-listed schemes (SMTP + common provider bridges; `sendmail`, `native` and `null` always refused), parse and build check (missing bridge detected), host SSRF guard: private / loopback / link-local / reserved / cloud-metadata addresses and `localhost` / `*.local` / `*.internal` names refused, host names resolved with a bounded lookup; `allowed_hosts` for an internal relay. A refused DSN fails the send (no silent fallback).
- **Redacted audit** — `MailerDsnAudit` (`scheme://host[:port]`, never credentials) in logs, the admin header and `SettingsChangedEvent` audit lines; transport errors are scrubbed of credentials before they are stored or shown.
- **Test e-mail** — "Send a test e-mail" next to the Save button (and `nowo:storage-kit:send-test`): synchronous, through the current configuration, CSRF + StorageKit access + Instance Settings section access, per-user rate limit, the transport's answer in the flash.
- **Resend** — "Check the API key" (`GET /domains`, timeout, no redirects; sending-only keys recognised) and a Resend test e-mail.
- **Sent-mail log** — one row per message (queued → sent / failed, attempts, provider id, size, tag); bodies only with `log.store_bodies` (values passed through `MailHeaders::redact()` masked); UiKit list (filters, server-side sortable columns, pagination) and detail pages; streamed **CSV export** of the filtered, sorted log (formula-injection safe, capped, bodies only on triple opt-in); `nowo:storage-kit:log:purge` (retention, keyset batches) and `nowo:storage-kit:log:forget <email>` (right to erasure).
- **Readiness** — `OutboundMailReadiness::isOutboundMailReady()`, Twig `nowo_storage_kit_outbound_mail_ready()` / `nowo_storage_kit_status()`, and an **AuthKit** `OutboundMailReadyCheckerInterface` adapter wired automatically when AuthKit is installed.
- **Fail-closed wiring** — the container refuses to compile without Instance Settings or the framework mailer, with the UI on but no form CSRF, with an empty role list, or with `allow_unauthenticated` outside `kernel.debug`.

## Installation

```bash
composer require nowo-tech/storage-kit-bundle
```

With Symfony Flex the recipe adds `config/packages/nowo_storage_kit.yaml` and the route import. Without Flex, register `NowoInstanceSettingsBundle` (and its dependencies) and `NowoStorageKitBundle`, then import the routes — see [docs/INSTALLATION.md](docs/INSTALLATION.md).

```yaml
# config/routes/nowo_storage_kit.yaml
nowo_storage_kit:
    resource: .
    type: nowo_storage_kit
```

Create the log tables with a migration (`php bin/console doctrine:migrations:diff`) and protect the prefix:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/mail, roles: ROLE_ADMIN }
```

## Quick start

```yaml
# config/packages/nowo_storage_kit.yaml
nowo_storage_kit:
    sender:
        default_from: 'noreply@example.com'   # default of the mailer.from setting
    log:
        retention_days: 30
```

Keep `MAILER_DSN` as the bootstrap fallback (`null://null` is fine), open **Settings → Mailer** (`/admin/settings/sections/mailer`), store a DSN such as `smtp://user:password@smtp.example.com:587`, click **Send a test e-mail**. Every `MailerInterface::send()` now goes through it:

```php
public function __construct(private MailerInterface $mailer) {}

$this->mailer->send((new Email())->to($user->getEmail())->subject('Welcome')->text('…')); // From filled from the settings
```

```twig
{% if nowo_storage_kit_outbound_mail_ready() %}<a href="{{ path('app_reset_password') }}">Forgot your password?</a>{% endif %}
```

Schedule `php bin/console nowo:storage-kit:log:purge` daily.

## Requirements

- PHP **8.2+** (`>=8.2 <8.6`; Symfony 8.x requires PHP 8.4+)
- Symfony **7.4**, **8.0** or **8.1** (FrameworkBundle with the mailer, Form, Validator, Security CSRF, RateLimiter, HttpClient, TwigBundle; SecurityBundle for the admin UI)
- `nowo-tech/instance-settings-bundle` **^1.0** (settings store, encrypted secrets, settings UI), Doctrine ORM **3.3+**, `nowo-tech/ui-kit-bundle` **^2.17.3**, `nowo-tech/form-kit-bundle` **^2.17**, `nowo-tech/outbound-url-guard-bundle` **^1.3**, `twig/extra-bundle`
- Optional: `symfony/resend-mailer` (Resend), `nowo-tech/auth-kit-bundle` (readiness adapter)

## Configuration

Every key, its default and its validation are documented in [docs/CONFIGURATION.md](docs/CONFIGURATION.md). Safe defaults: decorated mailer, SMTP + provider schemes only, private hosts refused, public-domain senders, 5 test e-mails per 10 minutes, log on without bodies, 30-day retention, UI under `/admin/mail` for `ROLE_ADMIN` only.

## Usage

Runtime DSN and fallback, sender, tags and redaction, readiness and AuthKit, the test e-mail, Resend, the sent-mail log and its retention: [docs/USAGE.md](docs/USAGE.md).

## Demo

- `demo/symfony8` — Symfony **8.1** (FrankenPHP PHP **8.5**, worker mode) with MySQL and **Mailpit**; app on host port **8085**, Mailpit UI on **8087**. The mailer section points at Mailpit; the home page sends a few messages and the log lists them.

Runtime mode is **`FRANKENPHP_MODE`** (`worker` default, or `classic`) — see [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

```bash
make -C demo up-symfony8          # http://localhost:8085 (Mailpit: http://localhost:8087)
make -C demo/symfony8 setup       # encryption key, schema, demo settings and messages
make test-e2e                     # Playwright e2e against the running demo
make demo-screenshots             # refresh docs/images/demo/*.webp
make demo-smoke                   # boot, HTTP 200, down (REQ-TEST-011)
```

## Development

```bash
make up
make install
make test
make cs-check
make phpstan
make igor
make test-coverage-100
make release-check
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Templates (UiKit page contract)](docs/TEMPLATES.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Roadmap](docs/ROADMAP.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Demo (FrankenPHP)](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit (kernel not reset)](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)

## Tests and coverage

- Tests: PHPUnit (PHP) — unit tests plus kernel integration tests (decorated mailer end to end with Messenger, settings-stored DSN, fallback, refused DSNs, sent-mail log on SQLite and, with `make test-with-db`, on the root-compose MySQL 8.4, admin UI with SecurityBundle and CSRF, Resend check with a mocked API, rate limit, CLI, opt-in routes, invalid configuration) and the UiKit page contract test; Playwright e2e on the demo
- PHP: 100% (1724/1724 statements, 1973/1973 elements, 224 tests; gate enforced in CI by `composer test-coverage-100`, no exclusions)
- TS/JS: N/A
- Python: N/A

## License and author

MIT · [Nowo.tech](https://nowo.tech) · [Héctor Franco Aceituno](https://github.com/HecFranco)
