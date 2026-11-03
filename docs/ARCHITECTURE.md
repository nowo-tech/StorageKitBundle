# Architecture

## Table of contents

- [Overview](#overview)
- [Components](#components)
- [Profile resolution](#profile-resolution)
- [Upload flow](#upload-flow)
- [Download flow](#download-flow)
- [Rendering flow](#rendering-flow)
- [Reference and purge flow](#reference-and-purge-flow)
- [Move and switch flow](#move-and-switch-flow)
- [Admin flow](#admin-flow)
- [Entities and tables](#entities-and-tables)
- [Container wiring](#container-wiring)
- [Worker safety](#worker-safety)
- [Extension points](#extension-points)

## Overview

StorageKitBundle stores images uploaded into texts in private S3-compatible buckets (Flysystem over async-aws), never on
the application's disk. Texts keep `storage-file:<uuid>` URIs; the application decides who reads each file (file
contexts), streams it or redirects to a short-lived pre-signed URL, tracks which text points at which file, purges
orphans and moves an owner's files between buckets with a verified copy. Owners are the instance (its bucket is the
`storage` Instance Settings section, or a configuration profile) and, optionally, scopes (organisations, tenants) with
their own buckets.

```
editor ──POST upload──▶ FileUploadController ─▶ ImageUploader ─▶ FileContexts (write) ─▶ StorageProfiles ─▶ ImageProcessor
                                                                         │                                   │
                                                          StoredFile row ◀─┴── FileStorage ─▶ S3 bucket ◀────┘
viewer ──GET file──▶ FileDownloadController ─▶ FileContexts (read) ─▶ FileDelivery ─▶ stream | 302 pre-signed URL
text saved (flush) ─▶ FileReferenceListener ─▶ FileReferences (storage_kit_file_reference, orphaned_since)
cron ─▶ nowo:storage-kit:purge ─▶ FilePurger ─▶ delete orphans (bucket, then row)
Move page / CLI ─▶ StorageMove / StorageSyncer ─▶ verified copy ─▶ switch (re-point files, retire old profile)
```

## Components

| Area | Classes |
| --- | --- |
| Storage | `Storage\FileStorage` (memoised connections), `S3StorageConnectionFactory` (async-aws S3 client, guarded HTTP client, private ACL), `StorageConnection` (write / read / exists / delete / keys / presign), `AsyncAwsUrlPresigner`, `PrivateVisibilityConverter`, `S3Settings` (validated connection, DSN parser, masked debug output), `StorageProvider` (`aws_s3`, `s3_compatible`), `StorageProbe` + `StorageProbeResult` (*Test connection*), `ErrorScrubber` |
| SSRF | `Security\EndpointGuard` (endpoint check + connect-time filter), `Form\ProfileConstraints` |
| Profiles | `Entity\StorageProfile`, `Profile\StorageProfiles` (resolution, settings, delivery), `Profile\ConfigProfiles` (`profiles` tree), `Repository\StorageProfileRepository` |
| Instance Settings | `Settings\StorageSettingsSection` (`storage` section, actions, notices), `Settings\InstanceStorageSettings` (read / write the section), `EventListener\InstanceSettingsListener`, `DependencyInjection\Compiler\InstanceSettingsPass` |
| Contexts and scopes | `Context\FileContextInterface`, `FileContexts` (registry, batched read check), `FileContextTarget`; `Scope\ScopeResolverInterface` (`TargetScopeResolver`), `ScopeAccessCheckerInterface` (`DenyAllScopeAccessChecker`), `StorageScope` |
| Upload | `Controller\FileUploadController`, `Upload\ImageUploader`, `ImageProcessor`, `ProcessedImage`, `UploadEndpoint` (editor config), `UploadLimitInterface`, `UploadRefused` |
| Download and render | `Controller\FileDownloadController`, `Download\FileDelivery`, `FileUrls`, `StoredImageResolver`, `StoredImage`; `Http\StorageCspSources`, `Integration\HttpHardening\StorageCspSourceProvider`; `Twig\StorageKitTwigExtension` |
| References and purge | `Reference\FileReferenceSourceInterface`, `FileReferenceListener`, `FileReferences`, `FileUris`, `ExistingIds`; `Lifecycle\FilePurger`, `Command\PurgeFilesCommand` |
| Moves | `Sync\StorageMove`, `StorageSyncer`, `SyncFailure`; `Entity\StorageSync`; `Message\RunStorageSyncMessage`, `MessageHandler\RunStorageSyncHandler`; `Command\StorageSyncCommand` |
| Admin UI | `Controller\StorageSettingsController`, `StorageMoveController`, `Form\StorageProfileType`, `Ui\AdminPages`, `StorageKitPages`, `ProfileTester`, `Bytes`; `Routing\StorageKitRouteLoader` |
| Access | `Security\AccessGuard`, `StorageAccessCheckerInterface` (`RoleAccessChecker`, `AllowAllStorageAccessChecker`), `EventListener\StorageKitAccessListener` |
| Data | `Entity\StoredFile`, `StoredFileReference`; repositories; `Doctrine\StorageEntityManager`, `TablePrefixListener`; `PersonalData\StoredFilesPersonalData` |
| Audit | `Audit\AuditRecorder`, `StorageAuditEvent` |

## Profile resolution

`StorageProfiles::forTarget(FileContextTarget)`:

1. With `scopes.enabled`, `ScopeResolverInterface::scopeOf()` gives the scope. If the scope has an active profile, it is
   used when usable (enabled, complete, connectable); otherwise uploads are **off** for that scope.
2. Else the instance: the config row of `instance_profile`, or the instance's active database row, or the
   settings-backed row (created on first use once the `storage` section has a provider).
3. Nothing usable = uploads off (`409 off`).

`settingsOf(profile)`: config rows → `ConfigProfiles::settings()` (unrestricted); settings rows →
`InstanceStorageSettings::settings()` (restricted unless `guard_instance_profile: false`); database rows → their own
fields (scopes always restricted). `deliveryOf(profile)`: `redirect` only for config profiles that ask for it, `proxy`
for everything else.

## Upload flow

1. `POST /storage/upload/{type}/{id}`: CSRF header (token id bound to the context) → signed-in user with
   `upload.access_roles` → rate limiter (`u:` + SHA-256 of the user identifier) → PHP upload valid → `max_bytes`.
2. `ImageUploader::upload()`: `writableTarget()` of the context (404) → profile and settings (409) →
   `ImageProcessor::process()` (sniff, pixels before decode, memory fit, re-encode, variants) → `UploadLimitInterface`.
3. Key `gmdate('Y/m')/<uuid v4>` (variants `<key>-preview`, `<key>-thumb`) written through `FileStorage`; then the
   `StoredFile` row (orphan from now). Any failure removes the objects already written (`503 storage`).
4. `file_uploaded` audit event; `201` with the Markdown snippet.

## Download flow

1. `GET /storage/files/{uuid}/{variant}`: `findOneByUuid()` → `FileContexts::readable(viewer, [file])` → 404 for missing
   and forbidden alike.
2. `FileDelivery::respond()`: the file's own profile → settings (404 when the profile is no longer connectable) →
   `redirect` profiles: `302` to `StorageConnection::presign()` (signed for the public endpoint when set);
   others: `StorageConnection::read()` streamed with the sandbox / `nosniff` headers. Both private, `noindex`,
   `no-referrer`.

## Rendering flow

`nowo_storage_images(text)` → `FileUris::storedIds()` (both URI forms, at most 200) → `StoredFileRepository::byUuids()`
(one query) → `FileContexts::readable()` (one call per context type, memoised per viewer for the request) →
`StoredImage` (preview `src`, original link, preview dimensions, alt).

## Reference and purge flow

- `FileReferenceListener::onFlush()` collects the entities of every `FileReferenceSourceInterface` whose text field
  changed (old or new value mentions a file); `postFlush()` calls `FileReferences::sync(type, id, text, context)`:
  references are replaced by the files of the text's own context; newly referenced files lose `orphaned_since`;
  files dropped by their last text get `orphaned_since = now`.
- `FilePurger::run(now, days)` (keyset batches, bounded per run): (1) forget references of sources `existing()` no
  longer returns; (2) forget references of files whose context `existing()` no longer returns; (3) mark unreferenced
  files orphaned; (4) for orphans older than the retention: delete each object from the file's own bucket, then the
  row (re-checking for a new reference first). Counters go to `files_purged`.

## Move and switch flow

1. The target profile (`state = target`, a database row) is saved from the Move page form (`move_target_saved`).
2. `StorageMove::start()` → `StorageSyncer::open(active, target)` (one `StorageSync` per pair, reopened to resume) →
   `RunStorageSyncMessage` on the bus (or the CLI).
3. `StorageSyncer::run()`: lock `lock.factory` on the sync + atomic claim (queued → running, or take over a running
   sync not updated for `stale_seconds`); files in id order after `cursor`, `batch_size` per query; per object read →
   SHA-256 compare (skip when the target already holds it) → write → read back and compare; cursor and counters saved
   after each file; stop at the first failure (`failed`, generic reason) or after the slice (queued again; the handler
   re-dispatches).
4. `StorageMove::switch()` (Move page only): refused for a configuration-managed instance, without a `completed` sync,
   or when files were uploaded after the cursor. In one transaction: `UPDATE storage_kit_file SET profile_id = target
   WHERE profile_id = active AND id <= cursor`; for the settings-backed instance, the active row keeps an encrypted
   copy of the section's connection (`keepConnection()`, becomes `source = database`), the target row becomes
   settings-backed and `InstanceStorageSettings::write()` saves the target's connection into the section; the active
   row is retired and the target activated. Memos reset; `move_switched` audited.

## Admin flow

`StorageKitAccessListener` (kernel.request priority 7, after the firewall) denies instance-page requests and
sub-requests the access checker refuses. Controllers check again (`AccessGuard::isGranted()` /
`isScopeGranted()` → 404 for scopes), build the FormKit form or CSRF-only action forms, call the services, flash a
translated outcome and redirect to a bundle route (path only). *Test connection* from the section posts to
`nowo_storage_kit_settings_test?from=settings` and is redirected back to the fixed section route only when `from` is
exactly `settings`.

## Entities and tables

All names take `doctrine.table_prefix`.

| Entity / table | Key columns | Indexes |
| --- | --- | --- |
| `StorageProfile` / `storage_kit_profile` | `uuid`, `scope` (null = instance), `state` (`active`, `target`, `retired`), `source` (`database`, `settings`, `config`), `config_name`, `enabled`, `provider`, `endpoint`, `public_endpoint`, `region`, `bucket`, `prefix`, `access_key`, `secret` (text, `#[Encrypted]`), `path_style`, `tested_at`, `test_ok`, `test_public_read`, `created_at`, `updated_at` | unique `uniq_storage_kit_profile_uuid`; `idx_storage_kit_profile_owner` (`scope`, `state`) |
| `StoredFile` / `storage_kit_file` | `uuid`, `profile_id`, `context_type`, `context_id`, `storage_key`, `original_name`, `mime_type`, `size`, `width`, `height`, `sha256`, `variants` (json), `total_bytes`, `uploaded_by`, `owner`, `created_at`, `orphaned_since` | unique `uniq_storage_kit_file_uuid`, `uniq_storage_kit_file_key` (`profile_id`, `storage_key`); `idx_storage_kit_file_context`, `_owner`, `_uploader`, `_orphaned` |
| `StoredFileReference` / `storage_kit_file_reference` | `file_id`, `source_type`, `source_id`, `created_at` | unique `uniq_storage_kit_file_reference` (`file_id`, `source_type`, `source_id`); `idx_storage_kit_file_reference_source` |
| `StorageSync` / `storage_kit_sync` | `uuid`, `from_id`, `to_id`, `status` (`queued`, `running`, `completed`, `failed`, `cancelled`), `last_file_id` (cursor), `total`, `processed`, `copied_objects`, `skipped_objects`, `error` (≤ 500, generic), `started_by`, `created_at`, `updated_at`, `finished_at` | unique `uniq_storage_kit_sync_uuid`; `idx_storage_kit_sync_pair` |

Settings-backed and config rows hold no connection data: the `storage` section (Instance Settings table, secret
encrypted by Instance Settings) and the `profiles` tree do.

## Container wiring

- `NowoStorageKitExtension::prepend()` adds the Doctrine attribute mapping and the FormKit profile `storage_kit`.
- `load()` registers storage, SSRF guard, profiles (and, with `instance_profile: null`, the section, its reader and its
  listener), files, lifecycle, access and HTTP services. Controllers are **not** autoconfigured; their routes come only
  from the `nowo_storage_kit` loader (REQ-SF-004 rule 7). The upload / download controllers and rate limiter exist only
  with `upload.enabled`; the pages, forms and access listener only with `web_ui.enabled`.
- Compiler passes: `TwigPathsPass` (namespace `@NowoStorageKitBundle`, host overrides first),
  `MessengerIntegrationPass` (removes the sync handler without a bus), `CsrfProtectionPass`, `DebugOnlySwitchesPass`,
  `InstanceSettingsPass`.

## Worker safety

Request-scoped memos (`FileStorage` connections, `StorageProfiles` profiles, `FileContexts` read decisions,
`FileReferenceListener` pending texts) implement `ResetInterface` and are tagged `kernel.reset`; a `storage` section
change resets the first two immediately. The entity manager is fetched per call. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Extension points

| Interface / service | Purpose |
| --- | --- |
| `Context\FileContextInterface` (tag `nowo_storage_kit.file_context`) | who may write / read a context; scope and owner of uploads |
| `Reference\FileReferenceSourceInterface` (tag `nowo_storage_kit.reference_source`) | texts that point at files |
| `Scope\ScopeResolverInterface` (`scopes.resolver`) | context target → scope |
| `Scope\ScopeAccessCheckerInterface` (`scopes.access_checker`) | who manages a scope's buckets |
| `Security\StorageAccessCheckerInterface` (`security.access_checker`) | who manages the instance buckets |
| `Upload\UploadLimitInterface` (`limits.upload_limit`) | plan quota per owner |
| `Storage\StorageConnectionFactoryInterface` (`nowo_storage_kit.connection_factory`) | bucket connections (tests: in-memory) |
| `Audit\StorageAuditEvent` | feed your audit log |
| `PersonalData\StoredFilesPersonalData` | GDPR export / erasure |
| `web_ui.templates`, `web_ui.layout_template`, `nowo_ui_kit.theme` | page markup and layout |
