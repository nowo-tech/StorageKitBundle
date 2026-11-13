# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/storage-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`); re-audited for `1.4.0` and `1.5.0` (see the Re-audit rows) |
| Audit date | 2026-11-03 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); unit tests of the memos and their reset; demo run in worker mode |
| **Verdict** | ✅ **Viable** in both scenarios. Four services keep request-scoped memos, all `ResetInterface` + `kernel.reset`; since 1.4 `RequestBoundaryListener` also clears them at the start of every main request, at `kernel.terminate` and after every Messenger message (handled or failed), so scenario B (no reset at all, `FRANKENPHP_RESET_KERNEL=false`) is covered too — see [Findings](#findings) |
| Re-audit | 1.4.0 (2026-11-11): request-boundary reset; kernel test serving 600 requests through `http_kernel` without the services resetter (`tests/Integration/Ddd/HostProvidersKernelTest.php`) |
| Re-audit | 1.5.0 (2026-11-12): **kernel reset false supported.** Dedicated entity manager (`doctrine.entity_manager`) recovered when closed and cleared at the start and at the end of every unit — main request, Messenger message, outermost console command — so even a unit whose end never ran leaves nothing behind (`OwnEntityManagerSubscriber`, console depth counter for nested commands); memos also cleared at the end of the outermost console command; download VO secrets in a `WeakMap` keyed by the per-request VO. Worker test `tests/Integration/Ddd/DddPortsKernelTest.php::testAKeptAliveWorkerAnswersEachUserWithTheirOwnFilesAndFlatMemory`: one kernel, `http_kernel` handle / terminate, no services resetter, **N = 1,200** downloads (600 iterations × 2 files) alternating **alice / bob** (each answer only what that user may read, bytes checked), a Messenger `WorkerMessageHandledEvent` and a `console.terminate` every 100 iterations, bundle manager empty (`UnitOfWork::size() === 0`) after every request, memory growth **< 2 MB** after a 100-iteration warm-up |
| Re-audit | 1.5.1 (2026-11-12, audit NM / NL2-v): the console depth counter of `OwnEntityManagerSubscriber` and `RequestBoundaryListener` could stay above zero after a throwing or propagation-stopping console listener, after which the dedicated manager was never cleared at request boundaries again. Units of work are now tracked at `PHP_INT_MAX` by `UnitOfWorkFrames` (one frame per console run keyed by command and input, saving and restoring the message / request state; failing runs without terminate dropped at the next unit; main requests counted). Test: `tests/Integration/Persistence/ConsoleBoundaryCounterKernelTest.php` |

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
| Doctrine | ✅ | `StorageEntityManager` fetches the entity manager per call (a closed / reset manager is replaced); bulk statements through DBAL. 1.5: a dedicated manager (`doctrine.entity_manager`) is recovered at the start of every main request / Messenger message and cleared at `kernel.terminate`, `console.terminate` and after every message (`OwnEntityManagerSubscriber`); the host's managers are never cleared by the bundle (see the host rule below) |
| Resources held open | ✅ | async-aws clients inside memoised connections, dropped on reset; GD images freed within the call |
| Memory growth across requests | ✅ (A) | Memos are cleared per request; GD peak RSS stays with the thread (see recommendations) |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: request-scoped memos, compile-time DI calls, route loader flag) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `FileStorage` | connections keyed by settings fingerprint | ✅ | ✅ since 1.4 (cleared at the request boundary; before: connections accumulated) |
| `StorageProfiles` | resolved profile rows per owner / config name | ✅ | ✅ since 1.4 (cleared at the request boundary; before: a switch made by another worker was not seen) |
| `FileContexts` | read decisions per viewer / context | ✅ | ✅ since 1.4 (cleared at the request boundary; before: a revoked read permission was reused for the same viewer) |
| `RequestBoundaryListener` (1.4) | unit-of-work frames, message flag and main-request counter (`UnitOfWorkFrames`, 1.5.1; command objects only, no request data); iterates the four memos above; 1.5: also at `console.terminate` | ✅ | ✅ |
| `OwnEntityManagerSubscriber` (1.5, dedicated manager only) | unit-of-work frames (`UnitOfWorkFrames`, 1.5.1; no request data); none else (fetches the bundle manager per call; recovers it when closed, clears it at every boundary) | ✅ | ✅ |
| `DeliverableFileData` (1.5) | static `WeakMap` keyed by each per-request `DeliverableFile` (pre-signed URL / body source) | ✅ | ✅ (entries die with their VO; nothing to reset) |
| `FileReferenceListener` | texts collected in `onFlush`, emptied in `postFlush` | ✅ | ✅ (emptied per flush; reset only drops a failed flush's leftovers) |
| `StorageKitRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `ImageProcessor`, `ImageUploader`, `UploadEndpoint`, `FileDelivery`, `FileDeliveryResponder`, `FileUrls`, `StoredImageResolver`, `FileUris`, `FileReferences`, `FilePurger`, `StorageSyncer`, `StorageMove`, `StorageProbe`, `EndpointGuard`, `ConfigProfiles`, `InstanceStorageSettings`, `AuditRecorder`, repositories | none (`readonly`) | ✅ | ✅ |
| Controllers, `AdminPages`, `StorageKitPages`, `ProfileTester`, form types, `StorageSettingsSection` | none (forms and view models built per call) | ✅ | ✅ |
| `AccessGuard`, `StorageKitAccessListener`, access checkers, `InstanceSettingsListener`, Twig extension, commands, Messenger handler | none | ✅ | ✅ |

Note (audit OB2): with a custom asynchronous Messenger `MessageExecutionStrategyInterface` the tracker loses its nested-worker protection (a worker run inside a handler may clear the outer message's unit); the boundary clears still happen.

## Findings

No finding under scenario **A**, which is what FrankenPHP's Symfony integration and Messenger workers
(`reset_on_message`) provide. Up to 1.3, under scenario **B** the three request-scoped memos above outlived the
request: a read permission revoked by the application was still honoured for the same viewer in that worker, an
un-published context stayed readable for anonymous visitors, and users sharing an identifier shared decisions. Since
1.4 the bundle clears them itself at the request boundary (`RequestBoundaryListener`: main `kernel.request`,
`kernel.terminate`, Messenger `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`), and `kernel.reset` stays as
well. Within a boundary, `FileContexts` memoises per viewer object (`WeakMap`), never per identifier.

## Usage recommendations in worker mode

- Keeping `kernel.reset` (the services resetter) and Messenger's `reset_on_message` is still recommended, but since
  1.4 the bundle does not depend on them.
- **Host rule (REQ-RUNTIME-002 rule 7).** A host that disables or bypasses the services resetter must clear its own
  entity managers at every boundary (main request, Messenger message, console command); otherwise its identity map
  serves stale rows across requests. With `doctrine.entity_manager: null` the bundle entities live in that host
  manager, so this applies to them too; with a dedicated manager (1.5) the bundle clears its own.
- Do not cache `StorageProfiles::forTarget()` results or `FileContexts::readable()` decisions in your own services.
- A long-running host command (a loop that resolves files for many viewers or tenants, a scheduler) has no request
  or message boundary: call `Nowo\StorageKitBundle\EventListener\RequestBoundaryListener::reset()` (public service)
  between items.
- GD keeps the peak RSS of an upload in the worker thread: lower `upload.max_pixels` on small hosts and set FrankenPHP
  `max_requests` (PERF-IMAGE-GD-PEAK-MEMORY).

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
