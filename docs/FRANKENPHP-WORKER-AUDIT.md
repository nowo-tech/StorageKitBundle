# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/storage-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-10-31 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); unit tests of the transport cache and reset; demo run in worker mode |
| **Verdict** | ✅ **Viable** — the only mutable state is the transport built from the last DSN (keyed by the DSN, so a changed DSN is picked up on the next send even without a reset) and the route loader's load-once flag; no request, user or message data survives a request |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `RuntimeTransport`: transport + DSN it was built from, last validated DSN + result; route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | `RuntimeTransport::reset()` (tag `kernel.reset`, also called on `SettingsChangedEvent` for the mail sections) — correct without it too (scenario B: the cache is keyed by the DSN read on every send) |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call; the rate-limit key is computed per request |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | Entity manager / connection resolved per call from the registry; log writes through DBAL (no identity map growth), reads through read-only entities |
| Resources held open | ✅ | An SMTP transport may keep its connection between sends (Symfony behaviour, ping threshold); dropped on reset |
| Memory growth across requests | ✅ | One cached transport at most; nothing appended per request |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: bundle extension cache, compile-time DI calls, route loader flag) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `RuntimeTransport` | transport + its DSN, last checked DSN + error; reset by `kernel.reset` and `SettingsChangedEvent` | ✅ | ✅ (DSN compared on every send) |
| `StorageKitRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `StorageKitMailer`, `SenderPolicy`, `MailSettings`, `SettingsMailerDsnProvider`, `MailerDsnValidator`, `DsnHostGuard`, `OutboundMailReadiness` | none (`readonly`; settings read per call) | ✅ | ✅ |
| `SentMailRecorder`, `SentMailRepository`, `TablePrefixListener` | none (`readonly`) | ✅ | ✅ |
| `TestMailSender`, `TestMailGuard` (limiter state lives in `cache.app`), `ResendApiChecker` | none | ✅ | ✅ |
| `StorageKitController`, `AdminPages`, `StorageKitPages`, `SettingsActions`, form types, sections | none (forms and view models built per call) | ✅ | ✅ |
| `AccessGuard`, `StorageKitAccessListener`, access checkers, `SettingsChangedListener`, Twig extension, commands | none | ✅ | ✅ |

## Findings

No worker-mode findings under scenario **B**. The DSN is read from Instance Settings on every send (memoized per main
request by Instance Settings itself), so a change saved in one worker is used by every other worker on its next message;
the cached transport is rebuilt because its DSN no longer matches.

## Usage recommendations in worker mode

- Do not cache `MailSettings` / DSN values in your own services' constructors; read them where you send.
- Messenger workers: `kernel.reset` runs between messages (`RuntimeTransport` is reset), and the worker resolves the DSN
  at delivery time.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
