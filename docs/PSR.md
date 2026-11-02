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
| PSR-3 Logger | **Adopted** | `RuntimeTransport`, `SentMailRecorder`, `ResendApiChecker`, `TestMailSender`, the settings listener and the CLI take an optional `Psr\Log\LoggerInterface` (redacted transport labels, field names, counts — never DSNs, keys, bodies or recipients). |
| PSR-6 / PSR-16 Cache | **N/A** | The test e-mail rate limit uses Symfony RateLimiter over `cache.app` (framework wiring); no package-owned cache API (the transport built from a DSN is per-process state reset on `kernel.reset`). |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for an admin UI. |
| PSR-18 HTTP client | **N/A** | The Resend key check uses `Symfony\Contracts\HttpClient\HttpClientInterface` (per-request `timeout` / `max_duration` / `max_redirects` options PSR-18 cannot express). |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection and tagged iterators only. |
| PSR-14 Event dispatcher | **N/A (consumer)** | The bundle listens to Instance Settings' `SettingsChangedEvent` (a plain event object) through the framework dispatcher; it dispatches no event of its own. |
| PSR-15 HTTP middleware | **N/A** | Symfony kernel listener for access; no middleware pipeline. |
| PSR-20 Clock | **Adopted** | Log timestamps (`queued_at`, `sent_at`, `failed_at`), the purge cut-off and the log period filters come from an injected `Psr\Clock\ClockInterface` (framework `clock`, else `NativeClock`). |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger) and PSR-20 (clock), type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains logging, HTTP, cache, clock, or event SPIs.

---

_REQ-CS-007 evaluation date: 2026-10-31._
