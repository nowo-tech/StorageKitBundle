# Feature Specification: StorageKitBundle baseline (1.0.0)

**Feature Branch**: `001-baseline`
**Created**: 2026-11-03
**Status**: Baseline (documents the whole product at 1.0.0; 1.1.0 adds FR-UI-007)
**Input**: Extraction of symfony-beacon spec 183 (*images in Markdown fields and S3-compatible object storage*, KIT-47)
into a reusable kit on top of InstanceSettingsBundle, with the fixes of the Beacon audit findings SEC-STORAGE-SSRF,
SEC-STORAGE-CSP-GLOBAL, SEC-FILE-REF-PIN and PERF-FILE-ORPHAN-UPDATE.

## Summary

`nowo-tech/storage-kit-bundle` (config root `nowo_storage_kit`) guarantees integrators:

- private S3-compatible buckets (providers `aws_s3`, `s3_compatible`) over Flysystem + async-aws, no local disk;
- an instance bucket kept in the `storage` Instance Settings section (secret encrypted) or in an ops configuration
  profile, and optional scope buckets managed by scope owners;
- image uploads for Markdown editors whose access is decided by host file contexts, with content sniffing, size / pixel
  / memory caps, re-encoding and random keys;
- downloads that answer 404 to anyone the context refuses, streamed with a sandbox CSP or redirected to short-lived
  pre-signed URLs (ops profiles only);
- reference tracking and a bounded orphan purge;
- verified, resumable bucket moves with a guarded switch;
- an SSRF guard for every bucket typed into a page, credentials never shown or logged, audit events and GDPR hooks.

## User Scenarios & Testing

### US-1 — Set up the instance bucket (P1)

**Given** an admin on the `storage` section, **when** they choose `s3_compatible`, fill in endpoint, bucket, access key
and secret and save, **then** the values are stored (secret encrypted), the profile memos are reset and a
`profile_saved` audit event lists the changed field names. **When** they press *Test connection*, **then** a probe object
is written, read, signed and deleted and the section shows *The connection works*.

- **Given** no provider, **then** uploads are off and the section says so.
- **Given** `aws_s3`, **then** the endpoint rows are hidden (`visible-when`) and their stored values are neither
  validated nor used.
- **Given** a public bucket, **then** the test passes with a warning.
- **Given** `instance_profile: main`, **then** the section is not registered and the Storage page is read-only.
- **Given** `aws_s3` and the console's `<user>_accessKeys.csv` in *Import from AWS CSV* (1.1.0), **then** the access
  key and the secret come from the file (nothing typed, or the same values typed); a different typed value, a wrong
  header, several rows, an oversize or binary file or a malformed key are form errors on that row and nothing is saved.

### US-2 — Upload an image into a text (P1)

**Given** a signed-in user with `ROLE_USER` and a context whose `writableTarget()` returns a target, **when** the editor
posts a PNG with a valid `X-CSRF-Token`, **then** the answer is `201` with `markdown: "![alt](storage-file:<uuid>)"`,
the original and variants are in the bucket under `yyyy/mm/<uuid>` and the file is an orphan until a text is saved.

- **Given** an SVG, an HTML file renamed `.png` or a corrupt image → `422 type`; above `max_bytes` → `413`; above
  `max_pixels` or too large to decode → `422 pixels`.
- **Given** a forged token → `403 csrf`; no upload role → `403 access`; an unknown or read-only context → `404`; no
  usable bucket → `409 off`; quota reached → `403 limit`; rate limit → `429`; a bucket failure → `503` and no object
  left behind.

### US-3 — Read images (P1)

**Given** a viewer the context lets read, **then** `GET /storage/files/<uuid>/preview` streams the bytes with
`nosniff`, `default-src 'none'; sandbox` and `attachment`. **Given** anyone else (or a missing uuid), **then** 404.
**Given** a config profile with `delivery: redirect`, **then** `302` to a pre-signed URL valid `url_ttl` seconds.
`nowo_storage_images(text)` returns only the images the viewer may read.

### US-4 — Purge orphans (P1)

**Given** a saved text that points at a file of its context, **then** a reference row exists and the file is not an
orphan. **When** the text drops it (or is deleted, or its context is), **then** the file becomes an orphan and
`nowo:storage-kit:purge` deletes its objects and row after `retention_days`. **Given** a text in another context that
mentions the uuid, **then** no reference is created.

### US-5 — Scope buckets (P2)

**Given** `scopes.enabled` and a scope manager, **when** they save a bucket for their scope, **then** uploads of contexts
in that scope go there. **Given** a private, loopback, metadata or unresolvable endpoint, **then** the form refuses it.
**Given** another user, **then** the scope pages answer 404. **Given** a scope bucket turned off, **then** that scope's
uploads are off (no fallback to the instance bucket).

### US-6 — Move to another bucket (P2)

**Given** a saved target bucket, **when** the operator starts copying, **then** every object is copied and verified by
SHA-256 (Messenger slices or `nowo:storage-kit:sync`), resumable after a failure. **When** they switch after a complete
copy, **then** every file points at the target, the old profile is retired and `move_switched` is audited.

- **Given** an incomplete copy or files uploaded since → switch refused.
- **Given** the settings-backed instance bucket → the switch writes the target connection into the `storage` section and
  the retired row keeps the old one encrypted.
- **Given** a configuration-managed instance bucket → switch refused.

### US-7 — Adopt an imported instance bucket (P3)

**Given** an active `database` instance row imported from an application's own tables, **when**
`nowo:storage-kit:adopt-instance-bucket` runs, **then** its connection is written into the `storage` section and the row
becomes the settings-backed active profile; existing files keep downloading.

### Edge cases

- An empty or malformed configuration profile makes that profile unusable (uploads off), logged by name only.
- A sync interrupted by a dead worker is taken over after `stale_seconds`; a duplicated Messenger message is harmless.
- A bucket that refuses a deletion keeps the row for the next purge run.
- GIF and animated WebP are stored as uploaded (metadata kept — residual risk).

## Requirements

### Bundle / DI

- **FR-DI-001** — `NowoStorageKitBundle` registers the extension `nowo_storage_kit` and the compiler passes.
- **FR-DI-002** — `Configuration` validates every key strictly with safe defaults; security switches are literal
  booleans; cross-key rules (instance profile exists, scope checker required, prefixes not nested, non-empty roles).
- **FR-DI-003** — `NowoStorageKitExtension` prepends the Doctrine mapping and the FormKit profile, requires
  DoctrineEncrypt (and SecurityBundle for the pages), registers storage, profiles, section (when `instance_profile` is
  null), files, lifecycle, access and HTTP services; controllers not autoconfigured.
- **FR-DI-004** — `CsrfProtectionPass` refuses the pages / upload endpoint without CSRF.
- **FR-DI-005** — `DebugOnlySwitchesPass` refuses `allow_unauthenticated` and `allow_private` outside `kernel.debug`.
- **FR-DI-006** — `InstanceSettingsPass` refuses to compile without Instance Settings when the section is needed.
- **FR-DI-007** — `MessengerIntegrationPass` removes the sync handler without a bus.
- **FR-DI-008** — `TwigPathsPass` registers `@NowoStorageKitBundle` with host overrides first.

### Storage

- **FR-STO-001** — `FileStorage` memoises one connection per settings fingerprint per request (`kernel.reset`).
- **FR-STO-002** — `StorageConnectionFactoryInterface` / `S3StorageConnectionFactory`: async-aws client, credentials
  only from settings, guarded HTTP client, timeouts, signing for the public endpoint.
- **FR-STO-003** — `StorageConnection` (write / read / exists / delete / bounded keys / presign) and
  `PrivateVisibilityConverter` (always `bucket-owner-full-control`, never public).
- **FR-STO-004** — `UrlPresignerInterface` / `AsyncAwsUrlPresigner`: local signing with type, disposition and private
  cache overrides.
- **FR-STO-005** — `S3Settings`: bucket, region, prefix, endpoint, key and secret validation; DSN parser; path style
  forced for guarded custom endpoints; browser origin; secret-free fingerprint and debug output.
- **FR-STO-006** — `StorageProvider`: `aws_s3` and `s3_compatible`, common and endpoint-only fields.
- **FR-STO-007** — `StorageProbe` / `StorageProbeResult`: guarded probe (write, read, presign, public read, delete),
  generic reason and status class.
- **FR-STO-008** — `ErrorScrubber`: generic reason + status; log message with key, secret and signature values masked.

### SSRF

- **FR-SSRF-001** — `EndpointGuard`: metadata always refused; private / local / single-label refused unless
  allow-listed or `allow_private`; DNS answers checked with a deadline; connect-time `NoPrivateNetworkHttpClient`
  without redirects.
- **FR-SSRF-002** — `ProfileConstraints`: shared field constraints (endpoint with the guard when restricted, region,
  bucket, prefix, access key, secret).

### Profiles

- **FR-PROF-001** — `StorageProfile`: owner (scope / instance), state (`active`, `target`, `retired`), source
  (`database`, `settings`, `config`), provider and fields, encrypted write-only secret, test result.
- **FR-PROF-002** — `StorageProfiles`: target resolution (scope → instance, no fallback from a turned-off scope),
  settings and delivery per profile, settings / config rows created on first use, memo reset.
- **FR-PROF-003** — `ConfigProfiles`: the `profiles` tree (DSN or fields), delivery, unusable entries logged by name.
- **FR-PROF-004** — `StorageProfileRepository`: active / target / retired (bounded) profiles, config rows, by uuid.

### Instance Settings

- **FR-SET-001** — `StorageSettingsSection`: the `storage` section (fields with `withVisibleWhen`, constraints,
  Test / Usage actions, notices).
- **FR-SET-002** — `InstanceStorageSettings`: provider, connection, secret and validated settings from the section;
  write of a whole connection (switch).
- **FR-SET-003** — `InstanceSettingsListener`: on `storage` changes, reset memos, forget the test result, audit.

### Contexts and scopes

- **FR-CTX-001** — `FileContextInterface` / `FileContextTarget`: host write / read / existence decisions, scope and
  owner of a target.
- **FR-CTX-002** — `FileContexts`: registry (unique, well-formed types), batched read check memoised per viewer.
- **FR-SCOPE-001** — `ScopeResolverInterface` / `TargetScopeResolver`.
- **FR-SCOPE-002** — `ScopeAccessCheckerInterface` / `DenyAllScopeAccessChecker` (fail closed).
- **FR-SCOPE-003** — `StorageScope`: key syntax and token-id suffix.

### Upload

- **FR-UP-001** — `FileUploadController`: CSRF header, roles, rate limit, PHP upload, size; JSON answer and error
  statuses; `no-store`.
- **FR-UP-002** — `ImageUploader`: context → bucket → image checks → limit → objects → row; cleanup on failure; audit.
- **FR-UP-003** — `ImageProcessor` / `ProcessedImage`: sniffing, pixel cap before decode, memory fit, re-encoding,
  orientation, variants.
- **FR-UP-004** — `UploadEndpoint`: editor configuration (`on` / `off` / `none`), MilkdownEditor options, CSRF token id.
- **FR-UP-005** — `UploadLimitInterface`: optional owner quota.
- **FR-UP-006** — `UploadRefused`: stable refusal reasons.

### Download and rendering

- **FR-DL-001** — `FileDownloadController`: context read check, 404 for missing and forbidden.
- **FR-DL-002** — `FileDelivery`: proxy (sandbox, nosniff, disposition) or pre-signed redirect; private, noindex.
- **FR-DL-003** — `FileUrls`: relative download and upload paths.
- **FR-DL-004** — `StoredImageResolver` / `StoredImage`: visible images of a text, batched.
- **FR-DL-005** — `StorageCspSources` / `StorageCspSourceProvider`: `img-src` origins of redirect profiles only.
- **FR-DL-006** — `StorageKitTwigExtension`: `nowo_storage_upload`, `nowo_storage_milkdown_upload`,
  `nowo_storage_file_url`, `nowo_storage_images`.

### References and purge

- **FR-REF-001** — `FileReferenceSourceInterface` / `ExistingIds`: host texts that point at files.
- **FR-REF-002** — `FileReferenceListener`: flush-time collection, post-flush sync.
- **FR-REF-003** — `FileReferences`: same-context references, orphan marking.
- **FR-REF-004** — `FileUris`: URI scheme, both URI forms, bounded id extraction, plain-text helper.
- **FR-PURGE-001** — `FilePurger`: four bounded keyset steps, per-bucket deletion, re-check before delete.
- **FR-PURGE-002** — `PurgeFilesCommand` (`nowo:storage-kit:purge`, `--days`).

### Moves

- **FR-MOVE-001** — `StorageMove`: target, start, cancel, discard, guarded switch (settings-backed swap).
- **FR-MOVE-002** — `StorageSyncer` / `SyncFailure`: verified, idempotent, resumable copy; lock + claim; check.
- **FR-MOVE-003** — `StorageSync` / `StorageSyncRepository`: sync state per pair.
- **FR-MOVE-004** — `RunStorageSyncMessage` / `RunStorageSyncHandler`: time-sliced background runs.
- **FR-MOVE-005** — `StorageSyncCommand` (`nowo:storage-kit:sync`, `--check`).
- **FR-MOVE-006** — `AdoptInstanceBucketCommand` (`nowo:storage-kit:adopt-instance-bucket`): database-source active
  instance bucket → `storage` section.
- **FR-MOVE-007** — `StorageMove::forgetRetired()` / `ForgetRetiredProfilesCommand` (`nowo:storage-kit:forget-retired`)
  and the Move page's *Forget previous buckets* (CSRF): retired profiles no file uses are deleted with their encrypted
  credentials, audited `profiles_forgotten` (audit CJ L3).

### Files data

- **FR-DATA-001** — `StoredFile`, `StoredFileReference`, `StoredFileRepository`: file metadata, variants, orphan date,
  usage queries (bounded).
- **FR-DATA-002** — `StorageEntityManager`, `TablePrefixListener`: configurable entity manager and table prefix.

### Personal data and audit

- **FR-GDPR-001** — `StoredFilesPersonalData`: export and erasure by user identifier, keyset pages, no bucket call.
- **FR-AUD-001** — `AuditRecorder` / `StorageAuditEvent`: one event per mutation, no secret.

### Admin UI and access

- **FR-UI-001** — `StorageSettingsController`: instance / scope Storage pages, test (literal `from=settings` back
  redirect), save with changed-field audit.
- **FR-UI-002** — `StorageMoveController`: Move pages and CSRF-bound actions.
- **FR-UI-003** — `StorageProfileType`: provider-dependent rows, write-only secret, read-only and restricted modes.
- **FR-UI-007** — *Import from AWS CSV* (1.1.0): `AwsAccessKeyCsv` / `AwsAccessKeyCsvException` parse the AWS IAM
  access-key CSV (≤ 4 KB, UTF-8 text only, BOM / CRLF / CR accepted, header names case-insensitive, `Access key ID` and
  `Secret access key` columns among any others, exactly one data row with the header's column count, key id
  `^(AKIA|ASIA)[A-Z0-9]{16}$`, secret 40 × `[A-Za-z0-9/+=]`), refusing with a reason code and never echoing the content.
  `AwsCsvImport` adds the optional unmapped `access_key_csv` `FileType` row (`visible-when` `aws_s3`, `accept`
  `.csv,text/csv,text/plain`, disabled on read-only forms) and a `PRE_SUBMIT` listener that, for the submitted provider
  `aws_s3`, fills `access_key` and the secret before validation; a typed secret or a changed access key that differs
  from the file is a `csv_conflict` form error; a file sent with another provider is ignored like every hidden row.
  The file is read from PHP's temporary upload only: never moved, stored, logged, audited or rendered back.
  `StorageSettingsSectionTypeExtension` adds the same row to the `storage` Instance Settings section form (secret path
  `[secret][value]`).
- **FR-UI-004** — `AdminPages`, `StorageKitPages`, `ProfileTester`, `Bytes`: view models, templates, test outcome.
- **FR-UI-005** — Templates `settings/edit`, `settings/_aside`, `move/edit` on the UiKit form scaffold.
- **FR-UI-006** — `StorageKitRouteLoader`: opt-in routes, groups switched by configuration.
- **FR-ACC-001** — `AccessGuard`: instance, scope and upload access; firewall-only token trust.
- **FR-ACC-002** — `StorageAccessCheckerInterface`, `RoleAccessChecker`, `AllowAllStorageAccessChecker` (debug only).
- **FR-ACC-003** — `StorageKitAccessListener`: instance-page requests and sub-requests checked before the controller.

### i18n

- **FR-I18N-001** — `NowoStorageKitBundle` catalogues in en, es, fr, de, it, pt, nl with key parity.

## Key Entities

- **StorageProfile** (`storage_kit_profile`) — a bucket of an owner; state, source, provider, fields, encrypted secret.
- **StoredFile** (`storage_kit_file`) — an uploaded image: profile, context, key, variants, checksum, orphan date.
- **StoredFileReference** (`storage_kit_file_reference`) — a text pointing at a file.
- **StorageSync** (`storage_kit_sync`) — a copy between two profiles: status, cursor, counters, generic error.
- **`storage` section** — Instance Settings rows `storage.<field>` (secret encrypted).

## Success Criteria

- **SC-001** — PHPUnit green on PHP 8.2–8.5 × Symfony 7.4 / 8.1; statement and element coverage of `src/` 100%, no
  exclusions.
- **SC-002** — PHPStan with the FrankenPHP classic + worker rulesets, Igor, Rector dry-run, PHP-CS-Fixer clean.
- **SC-003** — The code inventory maps every production file under `src/` ([code-inventory.md](code-inventory.md)).
- **SC-004** — Demo boots (HTTP 200) and the Playwright e2e uploads into a note, refuses SVG / forged tokens / private
  endpoints and runs a verified move with switch.
- **SC-005** — No log record, flash, audit event, view model or exception produced by the bundle contains a secret, an
  access key from a page-edited bucket, a signed URL or a provider error body.

## Assumptions

- InstanceSettingsBundle 1.3.1+ stores the section, encrypts secrets, renders the section page with `visible-when` rows
  and dispatches `SettingsChangedEvent`; the host registers the UiKit `visible-when` peer.
- DoctrineEncrypt is configured with a key kept outside the repository.
- The bucket provider implements the S3 API used by async-aws (PutObject, GetObject, HeadObject, DeleteObject,
  ListObjectsV2, pre-signed GET).

## Non-goals

A local-disk provider, non-image attachments, image transformations beyond two variants, a caching image proxy,
stripping GIF / animated WebP metadata (see [ROADMAP](../../docs/ROADMAP.md)), the Markdown renderer and editor
themselves, the host's permission model.

## Validation

```bash
make test-coverage-100
make phpstan cs-check rector-dry igor validate-phpdoc validate-translations
make release-check
```

See [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/USAGE.md`](../../docs/USAGE.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/SECURITY.md`](../../docs/SECURITY.md).
