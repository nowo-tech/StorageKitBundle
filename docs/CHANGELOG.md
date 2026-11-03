# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Import from AWS CSV** (FR-UI-007): the Amazon S3 bucket forms — scope Storage page and Move target
  (`StorageProfileType`) and the `storage` Instance Settings section (`StorageSettingsSectionTypeExtension`) — have an
  optional, unmapped file row `access_key_csv` (`data-testid="storage-kit-access-key-csv"`) for the `<user>_accessKeys.csv` file the AWS console offers when an
  access key is created. On submit it fills the access key and the (write-only) secret. Accepted: the console's
  2-column file and the older 5-column export, UTF-8 BOM, CRLF / CR line ends, header names in any case. Refused, as
  translated form errors on the row (7 locales): files above 4 KB, non-text content, a header without
  `Access key ID` / `Secret access key`, anything but exactly one data row, a key id not matching
  `^AKIA[A-Z0-9]{16}$` (temporary STS `ASIA…` keys get their own `csv_temporary` error: they need a session token
  the kit does not store), a secret that is not 40 base64 characters, and typed values that differ from the file
  (`csv_conflict`). The row is shown for `aws_s3` only (`visible-when`); a file sent with another provider is ignored.
  The file is only read from PHP's temporary upload: never moved, stored, logged, audited or rendered back. No
  JavaScript is needed (the browser's file input shows the chosen name), so the CSP is unchanged.

### Changed

- Docs: configuration `profiles` (e.g. `dsn: '%env(STORAGE_KIT_DSN)%'`) and `instance_profile` are documented as
  **ops-only**, for infrastructure buckets managed outside the application; the primary path is the encrypted database
  settings (`storage` section, `storage_kit_profile`). Supported, not deprecated.

### Fixed

- Demo: `LOCK_DSN` (`flock`) in `.env.example` and the demo compose file — a fresh demo clone failed on
  `doctrine:schema:update` / every page with *Environment variable not found: "LOCK_DSN"*.

## [1.0.0] - 2026-11-03

First release. Extracted from symfony-beacon (spec 183, *images in Markdown fields and S3-compatible object storage*).
Requires PHP `>=8.2 <8.6`, Symfony 7.4 / 8.x, Doctrine ORM 3.3+, `nowo-tech/instance-settings-bundle` ^1.3.1,
`nowo-tech/ui-kit-bundle` ^2.23, `nowo-tech/form-kit-bundle` ^2.17, `nowo-tech/doctrine-encrypt-bundle` ^2.10 and
`ext-gd`.

### Added

- **S3-compatible object storage** over Flysystem and async-aws (`async-aws/s3` ^2.8 || ^3.0): `FileStorage` with
  memoised connections, `S3StorageConnectionFactory`, private objects (`bucket-owner-full-control`), pre-signed GET URLs
  for the browser-facing endpoint, validated `S3Settings` (bucket, region, prefix, endpoints; `s3://` DSN parser; secret
  masked in debug output). Providers `aws_s3` (endpoint derived from the region) and `s3_compatible` (Cloudflare R2,
  Backblaze B2, Wasabi, Scaleway, RustFS, MinIO…: endpoint, public endpoint, path style).
- **Bucket profiles** (`storage_kit_profile`): per owner (instance or scope) one active, at most one target and any
  number of retired profiles; sources `database`, `settings` and `config`. Configuration `profiles` (DSN or fields,
  `delivery: redirect|proxy`) and `instance_profile` for an ops-managed instance bucket.
- **`storage` Instance Settings section** (instance bucket when `instance_profile` is null): provider, endpoint, public
  endpoint, region, bucket, prefix, access key, secret (encrypted Instance Settings secret), path style; provider-dependent
  fields with `withVisibleWhen()` (UiKit `visible-when` peer); empty provider = uploads off; notices (off, incomplete,
  last test failed); *Test connection* and *Usage and move* actions. `InstanceSettingsListener` resets the memos,
  forgets a stale test result and audits section changes.
- **Test connection** (`StorageProbe`): write, read back, pre-sign, anonymous-read check (public bucket warning) and
  delete, with a generic reason and the HTTP status class.
- **Scope buckets** (`scopes.*`): `ScopeResolverInterface`, `ScopeAccessCheckerInterface`, scope Storage and Move pages
  under `scopes.path_prefix`; FormKit `StorageProfileType` with the provider choice and `visible-when` rows (submitted
  provider decides, hidden rows keep their values, write-only secret encrypted by DoctrineEncrypt).
- **Image uploads** (`POST <upload.path_prefix>/upload/{type}/{id}`): CSRF header, `upload.access_roles`, per-user
  rate limit, content sniffing (PNG, JPEG, GIF, WebP), `max_bytes`, `max_pixels` before decoding, memory check,
  re-encoding (EXIF / GPS stripped, JPEG orientation applied), `preview` / `thumb` variants, random server-side keys,
  `UploadLimitInterface` quota; JSON answer with the Markdown snippet (`![alt](storage-file:<uuid>)`, `uri_scheme`).
  `FileContextInterface` lets the host decide write and read access per context. Twig `nowo_storage_upload()` and
  `nowo_storage_milkdown_upload()` (MilkdownEditorBundle 1.1 upload options).
- **Downloads** (`GET <upload.path_prefix>/files/{uuid}/{variant}`): read check per context, 404 for missing and
  forbidden alike, streamed with sandbox CSP, `nosniff`, `attachment` by default, or `302` to a short-lived pre-signed URL
  for configuration profiles with `delivery: redirect`; private cache. `StoredImageResolver` and Twig
  `nowo_storage_images()` / `nowo_storage_file_url()` for Markdown renderers; `StorageCspSources` and an HttpHardening
  `CspSourceProviderInterface` adapter.
- **References and purge**: `FileReferenceSourceInterface`, Doctrine flush listener, `storage_kit_file_reference`,
  orphan tracking, `nowo:storage-kit:purge` (four steps, keyset batches, `--days`).
- **Bucket moves**: Move page (target bucket, test, start / resume / cancel copying, switch, discard), Messenger
  background slices (`RunStorageSyncMessage`), `nowo:storage-kit:sync <from> <to> [--check]`; SHA-256-verified,
  idempotent, resumable copy; switch only after a complete verified copy with no upload since; for the settings-backed
  instance bucket the switch writes the target connection into the `storage` section and the retired row keeps an
  encrypted copy of the old one.
- **`nowo:storage-kit:adopt-instance-bucket`**: moves a database-source active instance bucket into the `storage`
  section (migration from an application's own storage tables).
- **Personal data**: `StoredFilesPersonalData::export()` / `erase()`.
- **Audit events**: `StorageAuditEvent` for every mutation (field names and ids only).
- **Admin UI** on the UiKit page contract: instance Storage page (usage, last test, Test, Edit connection, Move) and
  Move page; FormKit profile `storage_kit`; opt-in route loader `nowo_storage_kit`; `security.access_roles` /
  `access_checker` / `allow_unauthenticated` (debug only); `web_ui.templates` and `layout_template`; 7 locales (en, es,
  fr, de, it, pt, nl).
- **Doctrine**: `doctrine.table_prefix`, `doctrine.entity_manager`.
- **Demo** `demo/symfony8`: Symfony 8.1 on FrankenPHP (worker mode), MySQL and RustFS; Playwright e2e and README
  screenshots.

### Security

- **SSRF guard** for every bucket typed into a page (`EndpointGuard`, SEC-STORAGE-SSRF): structural endpoint validation,
  cloud metadata always refused, private / loopback / link-local / reserved / single-label hosts refused unless
  allow-listed (`endpoints.allowed_hosts`), IPv4-mapped and other IP spellings canonicalised, DNS resolution with a
  deadline, connect-time `NoPrivateNetworkHttpClient` without redirects, forced path style, `allow_private` only with
  `kernel.debug`, `guard_instance_profile` for the instance bucket.
- **No CSP widening by page users** (SEC-STORAGE-CSP-GLOBAL): only ops-configured redirect profiles add an `img-src`
  origin; page-edited buckets are always streamed.
- **Same-context references** (SEC-FILE-REF-PIN): a text references only files of its own context.
- **Bounded purge** (PERF-FILE-ORPHAN-UPDATE): keyset batches, `purge.max_batches` per run.
- **Secrets**: encrypted at rest (Instance Settings secret, DoctrineEncrypt), write-only fields, never in logs,
  exceptions, audit events, flashes or view models; provider messages scrubbed and kept out of pages (no response
  oracle).
- **Independent audit (Appendix CJ)**:
  - **M1** the request timeout, the total limit (4×) and "no redirects" are set on the `NoPrivateNetworkHttpClient`
    filter itself (it replaced the inner client's options with its defaults: 60 s idle, no total limit, 20 redirects);
    regression tests against a slow and a redirecting server.
  - **L1** progressive JPEGs are budgeted 6 more bytes per pixel (libjpeg coefficient buffers); default
    `upload.max_pixels` is 24 MP.
  - **L2 / L2b** proxied downloads ask the bucket for the stored size only (`Range: bytes=0-<size-1>`), are sent as
    they arrive and cut at the stored size; the rest of an oversized body is never read (no temp-file growth).
  - **N1** the progressive-JPEG memory budget applies to every JPEG (marker scans can be fooled by junk bytes).
  - **N2** the forget / repoint race and the foreign-key behaviour per database are documented.
  - **L3** retired profiles no file uses can be forgotten with their credentials: *Forget previous buckets* on the Move
    page and `nowo:storage-kit:forget-retired [--scope=|--all] [--dry-run]`, audited `profiles_forgotten`.
  - **I1** bucket, region and prefix patterns anchored with `D`; **I2** `S3Settings` secret private (`secret()`),
    masked in `json_encode()`, serialization refused; **I3** a *Switch* the `storage` section refuses is a handled
    error (flash) and changes nothing; **I4** Azure's wire server `168.63.129.16` refused like the other metadata
    endpoints, also at connect time; **I6** stable upload rate-limit key for non-`UserInterface` users (session, else
    client IP).
- **Fail-closed wiring**: no DoctrineEncrypt, no Instance Settings (when the section is needed), no SecurityBundle or no
  CSRF with the pages on, debug-only switches outside debug, non-literal security switches, empty role lists, scopes
  without an access checker — the container does not compile.
