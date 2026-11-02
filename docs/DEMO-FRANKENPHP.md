# Demo with FrankenPHP

This bundle includes a runnable demo with FrankenPHP in:

- `demo/symfony8` — Symfony **8.1** on FrankenPHP **PHP 8.5** (`dunglas/frankenphp:1-php8.5-alpine`)

**PHP version policy (Symfony 8 demos):** use the newest PHP minor published in official FrankenPHP images when the demo’s `require.php` and Symfony 8 allow it (today: **8.5**). Bump the Dockerfile tag when FrankenPHP adds a newer PHP and constraints still match.

The demo uses:

- Caddy on HTTP `:80` inside the container
- **`Caddyfile`**: **worker** mode (`php_server { worker ... }`) for throughput
- **`Caddyfile.dev`**: classic `php_server` (**no worker**) — hot-reload friendly

Runtime selection is via **`FRANKENPHP_MODE`** (`classic` | `worker`), not `APP_ENV`. Compose still sets **`APP_ENV=dev`** / **`APP_DEBUG=1`** and mounts **`docker/php-dev.ini`**.

## Table of contents

- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Worker mode and kernel reset](#worker-mode-and-kernel-reset)
- [Demo services and data](#demo-services-and-data)
- [Timeout hierarchy](#timeout-hierarchy)
- [Development stack in demos](#development-stack-in-demos)
- [Troubleshooting](#troubleshooting)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Playwright e2e and screenshots (REQ-DEMO-013)](#playwright-e2e-and-screenshots-req-demo-013)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony8
```

Then open:

- Symfony 8.1: `http://localhost:8085`
- Mailpit (the demo's mail catcher): `http://localhost:8087`

## Switching classic vs worker (`FRANKENPHP_MODE`)

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Use the worker Caddyfile |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Set in `.env` / `.env.example`. Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Worker mode and kernel reset

This package is audited as safe when FrankenPHP keeps the Symfony kernel warm **without** resetting it between requests: the DSN is read from the settings on every send and the only cached object, the transport of the last DSN, is rebuilt when the DSN changes (and dropped on `kernel.reset` / `SettingsChangedEvent`). Full checklist: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). Try it: in `/admin/settings/sections/mailer` tick "Remove the saved value" for the DSN, save, reload `/` — the transport is `default` (the `MAILER_DSN` fallback) without restarting the worker; `make seed` stores `smtp://mailpit:1025` again.

## Demo services and data

| Service | Image | Purpose |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` (+ `pdo_mysql`, `sodium`) | Symfony 8.1 app on port `${PORT:-8085}`; the entrypoint creates the database, the Halite key (`var/secrets/.Halite.default.key`, mode 0600, never committed) and the schema (`doctrine:schema:update` — demo only, applications use migrations) |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006 / REQ-DEMO-011) | `demo_instance_setting_value`, `demo_storage_kit_sent_mail`, `demo_storage_kit_sent_mail_body` (`doctrine.table_prefix: demo_` in both bundles) |
| `mailpit` | `axllent/mailpit` | SMTP on `mailpit:1025` (Docker network only), web UI on `127.0.0.1:${MAILPIT_UI_PORT:-8087}` |

`make setup` (or `make seed`) runs `app:demo:seed`: the mailer section gets `smtp://mailpit:1025` (encrypted; `mailpit` is a private Docker host, accepted through `nowo_storage_kit.dsn.allowed_hosts: [mailpit]`), sender `noreply@example.com` / "Acme Ops", Reply-To and test recipient; when the log is empty it sends three messages through `MailerInterface` (tags `welcome`, `invoice`, `password_reset` with a redacted one-time link) and records one failed delivery. `MAILER_DSN` stays `null://null` (fallback). The admin UIs are open without login (`security.allow_unauthenticated: true` in both bundles, accepted only because the demo runs with `kernel.debug`); `log.store_bodies` is on in the demo only.

## Timeout hierarchy

REQ-RUNTIME-001 applies: the bundle resolves DSN hosts (bounded DNS lookup) and calls the Resend API.

| Layer | Demo value |
| --- | --- |
| DNS lookup of a DSN host | 2 s (`nowo_storage_kit.dsn.dns_timeout`) |
| Resend key check | 10 s connect + total (`resend.api_timeout`) |
| SMTP to Mailpit | Symfony transport default (local, immediate) |
| PHP `max_execution_time` / `max_input_time` | 30 s (Caddyfile `php_ini`) |
| Caddy `servers.timeouts.write` | 40 s |
| FrankenPHP `max_wait_time` | 10 s (wait for a free PHP thread) |

## Development stack in demos

The demo includes:

- **Symfony Debug** (`symfony/debug-bundle`)
- **Symfony Web Profiler** (`symfony/web-profiler-bundle`)
- **Doctrine ORM** (MySQL), **DoctrineEncryptBundle** (Halite), **Instance Settings**, **UiKit**, **FormKit** and **Mailpit**
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **`APP_DEBUG=1`** in `.env.example`

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class     => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class       => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class               => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class          => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class      => ['all' => true],
    Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class                    => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class                => ['all' => true],
    Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class             => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class   => ['dev' => true, 'test' => true],
    Nowo\StorageKitBundle\NowoStorageKitBundle::class   => ['all' => true],
];
```

## Troubleshooting

- If app does not respond, run `make -C demo/symfony8 logs`.
- If routes/config changed, run `make -C demo/symfony8 cache-clear`.
- If dependencies are outdated, run `make -C demo/symfony8 update-bundle`.
- `Table '…demo_storage_kit_sent_mail' doesn't exist` right after `make up`: MySQL was still initialising; run `make -C demo/symfony8 setup`.
- "This mail server host is private…" when saving a DSN: only `mailpit` is allowed as a private host in the demo (`dsn.allowed_hosts`).
- Test e-mail "Connection could not be established": the `mailpit` container is not running (`docker compose ps`).
- The stored DSN reads as "not set" after deleting `var/`: the Halite key was regenerated; reseed (`make seed`).

## Demo smoke (REQ-TEST-011)

Boot the FrankenPHP demo, expect **HTTP 200** on the published port, then tear down:

```bash
make demo-smoke
# or: make -C demo demo-smoke
```

Smoke URL: `http://127.0.0.1:8085` (Symfony 8.1 demo). This target is also part of `make -C demo release-check` / root `make release-check`.

## Playwright e2e and screenshots (REQ-DEMO-013)

With the demo running:

```bash
make test-e2e            # or: make -C demo/symfony8 test-e2e
make demo-screenshots    # refreshes docs/images/demo/*.webp
```

Both run in the official Playwright image (`mcr.microsoft.com/playwright`, host network, one worker) and reseed the
demo first. The specs live in `demo/symfony8/e2e/tests/`: `demo.spec.ts` (runtime transport on the home page, stored
DSN never rendered and a private host refused, "Send a test e-mail" answer, a message sent through `MailerInterface`
found in the log and its detail, status filter, forged CSRF refused) and `screenshots.spec.ts` (two WebP captures with
the demo masthead, the bundle UI and the web debug toolbar; PNGs are converted with `sharp` and deleted).
