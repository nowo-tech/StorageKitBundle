# Storage Kit Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default), MySQL and RustFS (S3-compatible, two private buckets)
showing `nowo-tech/storage-kit-bundle`:

- the `storage` Instance Settings section (`/admin/settings/sections/storage`): provider choice (Amazon S3 or an
  S3-compatible service) showing only that provider's fields (UiKit `visible-when` peer), secret encrypted at rest and
  never rendered back, *Test connection*;
- the instance Storage page (`/admin/storage`): usage, last test, link to the section, *Move to another bucket*;
- the Move page (`/admin/storage/move`): a verified, resumable copy to the `storage-kit-archive` bucket, then *Switch*;
- notes (`/notes`, user `alice`) with an image uploaded through the JSON endpoint and served by the application
  (permission check, `nosniff`, sandbox CSP); SVG and HTML are refused.

Users (throw-away, loopback only): `admin` / `demo` (ROLE_ADMIN), `alice` / `demo` (ROLE_USER).

## Setup

```bash
make up      # containers + composer install, app on http://localhost:8213
make setup   # encryption key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

RustFS has no host port: the buckets are private and only the application reaches them (`endpoints.allowed_hosts:
[rustfs]` lets the SSRF guard accept the single-label Docker host).

## Commands

- `php bin/console app:demo:seed [--reset]` – create the buckets and the `storage` section (idempotent; `--reset`
  removes notes, files, moves, profiles and the section first)
- `php bin/console nowo:storage-kit:sync <from> <to> [--check]` – copy / compare two buckets (UUIDs on the Move page)
- `php bin/console nowo:storage-kit:purge` – orphan retention purge
- `make test-e2e` / `make demo-screenshots` – Playwright e2e with axe checks, README screenshots (REQ-DEMO-013)
- `make down` – stop the stack
