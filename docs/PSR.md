# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/storage-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `ConfigProfiles`, `ImageUploader`, `StorageProbe`, `StorageSyncer`, `FilePurger` and `AuditRecorder` take an optional `Psr\Log\LoggerInterface` (profile names, steps, scrubbed provider messages, counters — never secrets, access keys, signed URLs or file contents). |
| PSR-6 / PSR-16 Cache | **N/A** | The upload rate limit uses Symfony RateLimiter over `cache.app` (framework wiring); the connection / profile memos are per-request state reset on `kernel.reset`. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for the endpoints and the admin UI. |
| PSR-18 HTTP client | **N/A** | Storage requests go through async-aws on `Symfony\Contracts\HttpClient\HttpClientInterface` (per-request `timeout` / `max_duration` / `max_redirects` and the `NoPrivateNetworkHttpClient` decorator, which PSR-18 cannot express). |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection and tagged iterators only. |
| PSR-14 Event dispatcher | **N/A (Symfony contracts)** | `StorageAuditEvent` is dispatched through `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`; the bundle listens to Instance Settings' `SettingsChangedEvent`. |
| PSR-15 HTTP middleware | **N/A** | Symfony kernel listener for access; no middleware pipeline. |
| PSR-20 Clock | **N/A** | Timestamps (upload, orphan dates, test and sync times) use `DateTimeImmutable` directly; the purge and erasure take the reference time as an argument (`FilePurger::run($now, …)`), which is what tests drive. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger), type-hinted and wired (`logger`, null when absent).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains HTTP, cache, clock, or event SPIs.

---

_REQ-CS-007 evaluation date: 2026-11-03._
