# Demo with FrankenPHP

This bundle includes a runnable demo with FrankenPHP in:

- `demo/symfony8` — Symfony **8.1** on FrankenPHP **PHP 8.5** (`dunglas/frankenphp:1-php8.5-alpine`), MySQL 8.0 and
  [RustFS](https://rustfs.com) (S3-compatible object storage)

**PHP version policy (Symfony 8 demos):** use the newest PHP minor published in official FrankenPHP images when the demo’s `require.php` and Symfony 8 allow it (today: **8.5**). Bump the Dockerfile tag when FrankenPHP adds a newer PHP and constraints still match.

The demo uses:

- Caddy on HTTP `:80` inside the container, published on `127.0.0.1:${PORT:-8213}`
- **`Caddyfile`**: **worker** mode (`php_server { worker ... }`) for throughput
- **`Caddyfile.dev`**: classic `php_server` (**no worker**) — hot-reload friendly

Runtime selection is via **`FRANKENPHP_MODE`** (`classic` | `worker`), not `APP_ENV`. Compose still sets **`APP_ENV=dev`** / **`APP_DEBUG=1`** and mounts **`docker/php-dev.ini`**.

## Table of contents

- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Worker mode and kernel reset](#worker-mode-and-kernel-reset)
- [Demo services and data](#demo-services-and-data)
- [What to try](#what-to-try)
- [Timeout hierarchy](#timeout-hierarchy)
- [Development stack in demos](#development-stack-in-demos)
- [Troubleshooting](#troubleshooting)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Playwright e2e and screenshots (REQ-DEMO-013)](#playwright-e2e-and-screenshots-req-demo-013)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony8          # containers + composer install
make -C demo/symfony8 setup       # encryption key, schema, buckets and demo profile
```

Then open `http://localhost:8213`. Users (HTTP Basic, throw-away values, loopback-bound): **`admin` / `demo`**
(`ROLE_ADMIN`: storage pages) and **`alice` / `demo`** (`ROLE_USER`: notes and uploads).

## Switching classic vs worker (`FRANKENPHP_MODE`)

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Use the worker Caddyfile |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Set in `.env` / `.env.example`. Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Worker mode and kernel reset

The bundle's request-scoped memos (bucket connections, resolved profiles, per-viewer read decisions, pending
references) implement `ResetInterface` and are cleared on `kernel.reset` between worker requests; saving the bucket
connection also resets them immediately. Full checklist: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
Try it: switch the instance to the archive bucket on the Move page, then upload a note image — it lands in the new
bucket without restarting the worker.

## Demo services and data

| Service | Image | Purpose |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` (+ `pdo_mysql`, `gd`, `sodium`) | Symfony 8.1 app on `127.0.0.1:${PORT:-8213}`; the entrypoint creates the database, the DoctrineEncrypt Halite key (never committed) and the schema (`doctrine:schema:update` — demo only, applications use migrations) |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006) | bundle tables and the demo `Note` entity |
| `rustfs` | `rustfs/rustfs:1.0.1` (no host port) | S3-compatible storage on `rustfs:9000` (Docker network only); fake credentials `demo-access-key` / `demo-secret-key-not-real` |

`make setup` (or `make seed`) runs `app:demo:seed` (idempotent; `--reset` for e2e): it creates the two private buckets
`storage-kit-demo` and `storage-kit-archive` and points the instance bucket at the first one (S3-compatible provider,
endpoint `http://rustfs:9000`, path style). `rustfs` is a single-label private host, accepted only because the demo
lists it in `nowo_storage_kit.endpoints.allowed_hosts`. Every download goes through the application (no bucket port is
published). The demo app registers a `board` file context (`DemoBoardContext`) and a `note` reference source
(`NoteReferenceSource`); the Move job runs on Messenger's `sync://` transport.

## What to try

- `/notes` (alice): write a note, attach an image, save — the image is uploaded, referenced by the note and served by
  `/storage/files/<uuid>/preview`.
- `/admin/storage` (admin): status, usage, last test; **Test connection**.
- `/admin/storage/move`: save `storage-kit-archive` as the new bucket, **Start copying** (verified copy), **Switch to the
  new bucket**.
- An endpoint such as `http://169.254.169.254` or `http://127.0.0.1:9000` is refused by the SSRF guard.
- `docker compose exec php bin/console nowo:storage-kit:purge --days=0` deletes orphans (images of unsaved notes).

## Timeout hierarchy

REQ-RUNTIME-001 applies: the bundle resolves endpoint hosts (bounded DNS lookup) and talks to the bucket.

| Layer | Demo value |
| --- | --- |
| DNS lookup of an endpoint | 2 s (`nowo_storage_kit.endpoints.dns_timeout`) |
| One storage request | 30 s idle, 120 s total (`nowo_storage_kit.endpoints.request_timeout`) |
| PHP `max_execution_time` / `max_input_time` | 60 s / 30 s (Caddyfile `php_ini`) |
| Caddy `servers.timeouts.write` | 70 s |
| FrankenPHP `max_wait_time` | 10 s (wait for a free PHP thread) |

## Development stack in demos

The demo includes:

- **Symfony Debug** (`symfony/debug-bundle`)
- **Symfony Web Profiler** (`symfony/web-profiler-bundle`)
- **Doctrine ORM** (MySQL), **DoctrineEncryptBundle** (Halite), **Instance Settings**, **UiKit**, **FormKit**,
  **OutboundUrlGuard**, Messenger and **RustFS**
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **`APP_DEBUG=1`** in `.env.example`

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class          => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class            => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class           => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class                    => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class               => ['all' => true],
    Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class    => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class                         => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class                     => ['all' => true],
    Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class   => ['all' => true],
    Nowo\OutboundUrlGuardBundle\NowoOutboundUrlGuardBundle::class   => ['all' => true],
    Nowo\StorageKitBundle\NowoStorageKitBundle::class               => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class                  => ['dev' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class      => ['dev' => true, 'test' => true],
    Nowo\HotReloadBundle\NowoHotReloadBundle::class                => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class        => ['dev' => true, 'test' => true],
];
```

## Troubleshooting

- If app does not respond, run `make -C demo/symfony8 logs`.
- If routes/config changed, run `make -C demo/symfony8 cache-clear`.
- If dependencies are outdated, run `make -C demo/symfony8 update-bundle`.
- `Table '…storage_kit_profile' doesn't exist` right after `make up`: MySQL was still initialising; run
  `make -C demo/symfony8 setup`.
- *This address is not allowed* when saving an endpoint: only `rustfs` is allow-listed as a private host in the demo.
- *The storage could not be reached* on Test connection: the `rustfs` container is not running (`docker compose ps`).
- The saved secret reads as missing after deleting `var/`: the Halite key was regenerated; reseed (`make seed`).

## Demo smoke (REQ-TEST-011)

Boot the FrankenPHP demo, expect **HTTP 200** on the published port, then tear down:

```bash
make demo-smoke
# or: make -C demo demo-smoke
```

Smoke URL: `http://127.0.0.1:8213` (Symfony 8.1 demo). This target is also part of `make -C demo release-check` / root `make release-check`.

## Playwright e2e and screenshots (REQ-DEMO-013)

With the demo running:

```bash
make test-e2e            # or: make -C demo/symfony8 test-e2e
make demo-screenshots    # refreshes docs/images/demo/*.webp
```

Both run in the official Playwright image (`mcr.microsoft.com/playwright`, host network, one worker) and reseed the
demo first. The specs live in `demo/symfony8/e2e/tests/`: `demo.spec.ts` (public home and admin-only storage pages,
saving and testing the instance bucket, private and metadata endpoints refused by the SSRF guard, an image uploaded
into a note and downloaded through the application, SVG and a forged CSRF token refused by the JSON endpoint, a
verified copy to the archive bucket and the switch) and `screenshots.spec.ts` (`notes.webp`, `storage-settings.webp`,
`storage-move.webp`, with the demo masthead, the bundle UI and the web debug toolbar; PNGs are converted with `sharp`
and deleted).
