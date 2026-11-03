# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/storage-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-11-03 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); unit tests of the memos and their reset; demo run in worker mode |
| **Verdict** | ✅ **Viable** with the standard Symfony runtime (`kernel.reset` between requests, scenario A). Four services keep request-scoped memos, all `ResetInterface` + `kernel.reset`. Under scenario B (no reset at all) they would survive into the next request — see [Findings](#findings) |

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

- **A — kernel not rebooted, `services_resetter` still runs** (Symfony Runtime / FrankenPHP integration default).
- **B — no reset at all:** any state kept in a service survives into the next request.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ⚠️ A ✅ | `FileStorage` (connections per settings fingerprint), `StorageProfiles` (resolved profiles), `FileContexts` (read decisions per viewer), `FileReferenceListener` (texts pending until `postFlush`); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | The four services above; `StorageProfiles` and `FileStorage` also reset on a `storage` section change (`InstanceSettingsListener`) and after a save / switch |
| Request / user captured in services | ✅ | `AccessGuard` reads the token and the main request per call; rate-limit key computed per request |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None (`ImageProcessor` only reads `memory_limit`) |
| Doctrine | ✅ | `StorageEntityManager` fetches the entity manager per call (a closed / reset manager is replaced); bulk statements through DBAL |
| Resources held open | ✅ | async-aws clients inside memoised connections, dropped on reset; GD images freed within the call |
| Memory growth across requests | ✅ (A) | Memos are cleared per request; GD peak RSS stays with the thread (see recommendations) |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: request-scoped memos, compile-time DI calls, route loader flag) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `FileStorage` | connections keyed by settings fingerprint | ✅ | ⚠️ connections kept (keyed by settings, so a changed bucket gets a new one; old ones accumulate) |
| `StorageProfiles` | resolved profile rows per owner / config name | ✅ | ⚠️ a switch or scope change made by another worker is not seen until reset |
| `FileContexts` | read decisions per viewer / context | ✅ | ⚠️ a revoked read permission could be reused for the same viewer |
| `FileReferenceListener` | texts collected in `onFlush`, emptied in `postFlush` | ✅ | ✅ (emptied per flush; reset only drops a failed flush's leftovers) |
| `StorageKitRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `ImageProcessor`, `ImageUploader`, `UploadEndpoint`, `FileDelivery`, `FileUrls`, `StoredImageResolver`, `FileUris`, `FileReferences`, `FilePurger`, `StorageSyncer`, `StorageMove`, `StorageProbe`, `EndpointGuard`, `ConfigProfiles`, `InstanceStorageSettings`, `AuditRecorder`, repositories | none (`readonly`) | ✅ | ✅ |
| Controllers, `AdminPages`, `StorageKitPages`, `ProfileTester`, form types, `StorageSettingsSection` | none (forms and view models built per call) | ✅ | ✅ |
| `AccessGuard`, `StorageKitAccessListener`, access checkers, `InstanceSettingsListener`, Twig extension, commands, Messenger handler | none | ✅ | ✅ |

## Findings

No finding under scenario **A**, which is what FrankenPHP's Symfony integration and Messenger workers
(`reset_on_message`) provide. Under scenario **B** the three request-scoped memos above would outlive the request; the
bundle relies on `kernel.reset` for them, and saving the `storage` section resets the profile and connection memos in
the worker that saved it.

## Usage recommendations in worker mode

- Keep `kernel.reset` running between requests (do not disable the services resetter) and Messenger's
  `reset_on_message` (default) for the move worker.
- Do not cache `StorageProfiles::forTarget()` results or `FileContexts::readable()` decisions in your own services.
- GD keeps the peak RSS of an upload in the worker thread: lower `upload.max_pixels` on small hosts and set FrankenPHP
  `max_requests` (PERF-IMAGE-GD-PEAK-MEMORY).

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
