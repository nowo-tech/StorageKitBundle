# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[2.2.0] - 2026-11-26](#220---2026-11-26)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[2.1.0] - 2026-11-22](#210---2026-11-22)
  - [Added](#added-1)
  - [Changed](#changed-1)
  - [Fixed](#fixed-1)
- [[2.0.1] - 2026-11-22](#201---2026-11-22)
  - [Fixed](#fixed-2)
- [[2.0.0] - 2026-11-21](#200---2026-11-21)
  - [Removed](#removed)
  - [Changed](#changed-2)
  - [Security](#security)
- [[1.8.1] - 2026-11-20](#181---2026-11-20)
  - [Fixed](#fixed-3)
- [[1.8.0] - 2026-11-20](#180---2026-11-20)
  - [Added](#added-2)
  - [Deprecated](#deprecated)
  - [Changed](#changed-3)
- [[1.7.1] - 2026-11-19](#171---2026-11-19)
  - [Fixed](#fixed-4)
- [[1.7.0] - 2026-11-19](#170---2026-11-19)
  - [Added](#added-3)
  - [Fixed](#fixed-5)
  - [Changed](#changed-4)
- [[1.6.1] - 2026-11-15](#161---2026-11-15)
  - [Fixed](#fixed-6)
  - [Tests](#tests)
- [[1.6.0] - 2026-11-15](#160---2026-11-15)
  - [Added](#added-4)
  - [Changed](#changed-5)
  - [Tests](#tests-1)
- [[1.5.3] - 2026-11-13](#153---2026-11-13)
  - [Security](#security-1)
  - [Added](#added-5)
  - [Changed](#changed-6)
  - [Tests](#tests-2)
- [[1.5.2] - 2026-11-13](#152---2026-11-13)
  - [Security](#security-2)
  - [Fixed](#fixed-7)
  - [Tests](#tests-3)
- [[1.5.1] - 2026-11-12](#151---2026-11-12)
  - [Fixed](#fixed-8)
  - [Tests](#tests-4)
- [[1.5.0] - 2026-11-12](#150---2026-11-12)
  - [Added](#added-6)
  - [Changed](#changed-7)
  - [Deprecated](#deprecated-1)
- [[1.4.0] - 2026-11-11](#140---2026-11-11)
  - [Added](#added-7)
  - [Security](#security-3)
  - [Fixed](#fixed-9)
  - [Changed](#changed-8)
- [[1.3.0] - 2026-11-11](#130---2026-11-11)
  - [Fixed](#fixed-10)
  - [Added](#added-8)
  - [Changed](#changed-9)
  - [Deprecated](#deprecated-2)
- [[1.2.0] - 2026-11-10](#120---2026-11-10)
  - [Security](#security-4)
  - [Added](#added-9)
  - [Changed](#changed-10)
- [[1.1.0] - 2026-11-03](#110---2026-11-03)
  - [Added](#added-10)
  - [Changed](#changed-11)
  - [Fixed](#fixed-11)
- [[1.0.0] - 2026-11-03](#100---2026-11-03)
  - [Added](#added-11)
  - [Security](#security-5)

## [Unreleased]

## [2.2.0] - 2026-11-26

Generic, non-image files (a prerequisite of ChatKit 2.7 attachments, Beacon spec 259). Minor, no BC break; one
additive schema change ([UPGRADING](UPGRADING.md#to-220)).

<a id="added-unreleased"></a>

### Added

- **Generic file uploads (`Upload\FileUploaderInterface::uploadFile()`).** Bytes stored as they are, from a local
  file (`Upload\StoredFileSource`: path + size), streamed to the bucket and hashed in chunks (never one PHP string).
  The caller declares the type it sniffed; StorageKit accepts it only when it is exactly (`===`) one of the context's
  `acceptedTypes()` and checks a declared PNG / JPEG / GIF / WebP against its magic bytes. Only contexts implementing the
  new optional `Context\GenericFileContextInterface` (`acceptedTypes()`, `maxBytes()`) take generic files (deny by
  default). Same DBAL insert, savepoint, owner quota and `afterCommit()` rules as `uploadImage()`; no lock is held
  while the bytes travel. Refusal reason `UploadRefused::SOURCE` (`source`: the local file is not a regular file of the
  declared size) with its message in the 7 catalogues. Names are kept as labels (`Upload\FileNames`: no path, control
  or bidi characters, ≤ 200 characters).
- **Generated variants (`Upload\FileVariantWriterInterface::putVariant()`).** Attaches a `thumb` / `preview` raster
  image to a stored file found by exact UUID and context: object first under a new random key, then one short unit that
  locks the row (`SELECT … FOR UPDATE`), re-checks the context and expiry and writes the variant list and byte total;
  the replaced object is deleted after the commit. Inside your transaction the unit is a savepoint, so nothing is
  deleted until you call `afterVariantCommit($view)` (the replaced object) or `afterVariantRollback($view)` (the new
  one) once you know how it ended (audit ZCK-SK-L1).
- **The image pipeline as a port (`Upload\ImageThumbnailerInterface`, `Upload\GdImageThumbnailer`).** Pixel cap before
  decoding, memory guard, always re-encoded (EXIF / GPS / XMP dropped; a GIF becomes a PNG of its first frame), longest
  side 16–4096 px (`ImageProcessor::thumbnail()`, `ImageProcessor::header()`).
- **Server-side reads (`Download\FileReaderInterface::open()` → `Download\FileStream`).** A bounded read stream of a
  variant (exact UUID and context, optional byte limit) for scanners and preview builders, with `close()`; it cannot
  be cloned or serialized.
- **Context-bound delivery with `Range` (`Download\FileRangeDeliveryInterface::deliverInContext()`,** implemented by
  `FileDelivery`). A single `bytes=` range answers `206` (`Content-Range`), one past the end `416`
  (`DeliverableFile::partial()` / `unsatisfiable()`, `Download\ByteRange`); an inline request is honoured only for
  re-encoded images and audio / video; an optional shorter pre-signed lifetime. `Download\MediaTypes` holds the type
  rules: active content (HTML, SVG, XML, script) is always served as `application/octet-stream`, `attachment`, `.bin`.
- **Size limit per context**: `GenericFileContextInterface::maxBytes()`, capped by the new `upload.files.max_bytes`
  (default 100 MiB, at most 1 GiB; also the thumbnailer's largest source). Image contexts keep `upload.max_bytes`.
- `StoredFileRepository::findInContext()`, `StoredFile::isStoredAsUploaded()` / `servedVariant()`,
  `StorageConnection::writeStream()` / `readStream()` and an `$offset` for `readRange()`.
- `Audit\StorageAuditEvent::$occurredAt` (REQ-ARCH-001 rule 4: events carry ids and the time).
- Tests: generic files on SQLite and the three databases, two-connection races (`GenericFilesRaceTest`: row lock wait,
  removal committed before the locking read, two writers of one variant, a real deadlock inside the host transaction)
  and a 300-round worker test.

<a id="changed-unreleased"></a>

### Changed

- **The download route answers `Range`.** `GET <prefix>/files/{uuid}/{variant}` passes a single `Range` to the port when
  it implements `FileRangeDeliveryInterface` (the bundle's does): `206` / `416`, the whole file with `If-Range`. Image
  downloads keep their 1.4 headers and disposition; generic files are always `attachment` unless audio / video with
  `download.disposition: inline`, carry `Accept-Ranges: bytes`, and a variant that was not written is a 404 (only
  re-encoded images fall back to the original). A host port that implements only `FileDeliveryInterface` keeps `200`.
- **`storage_kit_file.mime_type` holds 127 characters** (was 32), for types such as
  `application/vnd.openxmlformats-officedocument.presentationml.presentation`.
- **Bucket moves stream each object** through a 2 MiB memory buffer that spills to a temporary file, and hash it in
  chunks (was: the object read whole into memory twice), so 100 MiB files move without the memory to match.
- An image upload into a `GenericFileContextInterface` context also follows the context's type list and size limit.
- **Removal and purge read the object keys under a row lock (audit ZCK-SK-M1).** `FileRemoverInterface::remove()` and
  the purge now lock the file row, drop its references, expire it and read every object key under that lock
  (`Lifecycle\FileClaims`), then delete the objects with no lock held, then the row. A variant written concurrently is
  either in that list or refused (its locking read sees the expiry), so no derived copy (a thumbnail of a deleted
  photo) is left in the bucket with no row. A bucket that refuses now leaves the row expired (no longer served) for
  the next purge, which retries every key on it.
- `StoredFileSource` refuses stream wrappers (`php://`, `phar://`, `data:`…): a source is a local file path
  (`StoredFileSource::isLocal()`, audit ZCK-SK-I2).

<a id="fixed-unreleased"></a>

### Fixed

- **Lost savepoint (REQ-RUNTIME-003 rule 3).** When a unit's own rollback failed (a MySQL / MariaDB deadlock already
  rolled back the whole transaction, so its savepoint was gone), `StorageEntityManager::atomically()` and `insert()`
  swallowed that failure and left the connection with a stale transaction nesting level: the original error was
  rethrown, but the connection was not repaired. They now roll back to the level the unit started at and, when that
  fails, close the connection (`StorageEntityManager::rollBackTo()` / `rollBackSavepoint()`) before rethrowing the
  original error, so the next use reconnects clean. Contention only (no data loss): no GHSA.
- **Owner quota under contention (all versions, audit ZCK-SK-L2).** `UploadLimitInterface::allows()` is asked before
  the bytes are stored, without a lock, for images (since 1.0) and generic files: concurrent uploads of one owner can
  together exceed a quota that had room for one of them (by up to N × the largest upload). Not changed in code; now
  documented in CONCURRENCY.md with the way to bound it (your own per-owner lock or counter). Contention only: no GHSA.

## [2.1.0] - 2026-11-22

### Added

- **Row versions on the bucket forms (REQ-RUNTIME-003 rule 4, lost update).** The Storage page and the Move page's
  new-bucket form were last-write-wins: two admins editing the same bucket both saved, and the second save silently
  replaced the first. `storage_kit_profile` gets an additive `row_version INT NOT NULL DEFAULT 1` column (migration in
  [UPGRADING](UPGRADING.md#to-210)). Each form carries the row and the version it was built from in a hidden
  `rowVersion` field (`<row id>:<row version>`, from the same load as the values); the save advances the version with
  one atomic compare-and-set inside its existing write unit (`Concurrency\RowVersion`). A page of another bucket is
  stale too: a page opened before its bucket was replaced (a target discarded and saved again, or a Move switch) never
  saves the new row, even at an equal version. A page that is no longer current writes nothing and answers 409
  with the form, a translated message (7 locales) and `X-Error-Code: STORAGE_KIT_STALE_EDIT` (a domain answer: no
  `X-Conflict-Code`, no `Retry-After`). Lock conflicts keep `CONFLICT_001` / `CONFLICT_002`.
- `StorageMove::saveTarget()` takes an optional fifth argument `?int $expectedVersion` (throws
  `Concurrency\StaleEditException` when it no longer matches); without it the version is advanced unchecked, so a
  Move page opened before your save is refused. `StorageMove::rowVersion()` reads the current version.
  `StorageProfileType` takes the `row_id` and `row_version` options (default null: no field),
  `StorageProfileType::submittedRowVersion($form, $profile)` returns the version only for the token of `$profile`'s
  row, and `StorageProfile::getRowVersion()`. Direct ORM writes of your own to a `StorageProfile` do not advance the
  version; save through `StorageMove::saveTarget()` for that.

### Changed

- A first save of a bucket whose page did not show it (another admin created the owner's bucket meanwhile) now answers
  the stale 409 instead of overwriting that bucket.

### Fixed

- No translation changed. A new strict `TranslationCatalogueTest` fails on a non-en locale missing a key, on a non-en value identical to English (any length, placeholders included; values without letters are skipped) unless allow-listed per locale (brands, units, protocols, loanwords, true cognates), on a non-es value identical to the Spanish one (longer than 12 characters, different from English) unless allow-listed as genuinely shared, and on stale entries in either allow-list.

## [2.0.1] - 2026-11-22

### Fixed

- `Sync\StorageMove::saveTarget()` without its fourth argument (`$configure`) saved nothing for a saved target the
  bundle loads again through its own manager (2.0): a target your manager loaded, or one the bundle loaded before a
  unit-of-work boundary dropped the private manager (FrankenPHP worker mode included). The connection you set on it
  was replaced by the stored row, yet the call returned `true` and audited `move_target_saved`. The connection set on
  the target you pass (provider, enabled, endpoints, region, bucket, prefix, access key, path style and a new secret)
  is now applied to the bundle's instance inside the same write unit under the owner lock; without a new secret the
  stored one is kept. The Move page always passes `$configure` and was not affected (audit YH-1).
- Docs after 2.0 (audit YH-2): USAGE and CONFIGURATION no longer describe the removed `ImageUploader::upload()` or the
  1.x flushes of your default manager by the admin pages and commands; README lists `nowo-tech/transaction-doctrine`;
  the `FileDeliveryPortsPass` docblock no longer calls the `FileDelivery` id deprecated or mentions `respond()`;
  UPGRADING links and duplicated 1.8.0 / 1.8.1 headings fixed; `make test-with-db` runs the CI database files instead
  of the removed host-mode runs.

## [2.0.0] - 2026-11-21

2.0.0: the major that removes what 1.x deprecated (REQ-BC-001) and closes REQ-ARCH-001 rules 6 and 8. An application
that runs 1.8 without deprecation notices from this bundle upgrades without code changes; with the settings-backed
instance bucket it also requires `nowo-tech/instance-settings-bundle` itself. See [UPGRADING](UPGRADING.md#to-200).

### Removed

- **BREAKING:** the host mode of the bundle's entity manager (deprecated in 1.8.0): the bundle never reads, writes or
  flushes its entities through your default entity manager any more. `doctrine.private_entity_manager: false` fails the
  build where 1.8 warned about it (entities on your default manager); the two 1.8 notices
  (`NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION` / `..._FALSE_DEPRECATION`) and
  `NowoStorageKitExtension::privateEntityManagerNotice()` are gone, as is the DBAL write-unit branch of
  `StorageEntityManager::transactional()` that flushed your manager.
- **BREAKING:** the host-flush probe of 1.8 (`Doctrine\StorageHostFlushReporter`, `@internal`, and its service): there
  is no host flush left to report.
- **BREAKING:** `Download\FileDelivery::respond()` (deprecated in 1.5.0): call `FileDeliveryInterface::deliver()` and
  `FileDeliveryResponder::toResponse()`.
- **BREAKING:** `Upload\ImageUploader::upload()`, the upload that returned the `StoredFile` entity (deprecated in
  1.3.0, runtime notice since 1.8.0): call `ImageUploaderInterface::uploadImage()` (a `StoredFileView`).
- `Doctrine\UploaderCollationListener::postGenerateSchemaTable()`, the no-op kept by 1.8.1 (deprecated there; never
  subscribed since 1.8.1). The `postGenerateSchema` collation and its foreign-key regression tests stay.
- CI: the "host mode" database legs.

### Changed

- **BREAKING:** `nowo-tech/instance-settings-bundle` moved from `require` to `suggest` (announced in 1.3.0, REQ-ARCH-001
  rule 8), with `conflict: <1.3.1`. The settings-backed instance bucket (`instance_profile: null`, the default) needs
  it: without it the container build fails with a message that starts with `composer require
  nowo-tech/instance-settings-bundle` (also for an application that only had it through this package and lost it on
  `composer update`). A config-pinned bucket does not need it; no class typed against it is registered then.
- **BREAKING:** the bundle entities are always written through the private entity manager on your default manager's
  connection, mapping and tables (the 1.8 opt-in): admin pages and console commands never flush your pending changes.
  `doctrine.private_entity_manager` defaults to it; `true` has no effect there (a 2.0 deprecation; removed in 3.0).
  `doctrine.entity_manager: <dedicated manager>` keeps working as in 1.x (used directly; with `true`, the private
  manager on it, as in 1.8; with `false`, no effect and a 2.0 deprecation, since 1.x gave no notice for it).
- `StorageEntityManager`: the fifth constructor argument (the 1.8 host-flush reporter) is kept, typed `?object` and
  ignored (deprecated since 2.0, removed in 3.0). `manager()` refuses to return your default manager (no private
  manager and no dedicated one). New `adopt()`: the bundle's own instance of a bundle entity.
- The admin write services (`Sync\StorageMove`, `Sync\StorageSyncer`, `Ui\ProfileTester`) load a bundle entity your
  manager handed them again through the bundle's manager (by id), so it is never taken for a new row; a row that no
  longer exists fails with a clear `LogicException`.
- The Flex recipe gets a 2.0 version: no `private_entity_manager` line, and the note that the Settings → Storage page
  needs `composer require nowo-tech/instance-settings-bundle`.
- CI triggers on the `1.x` maintenance branch; new "Without Instance Settings" job (`--no-coverage`); MySQL 8.4 and
  MariaDB 11.4 database legs with DBAL 3; the dedicated-manager kernel test runs on every database.
- Demo: runs in UiKit's demo platform shell (`@NowoUiKit/demo_platform/layout.html.twig`, UiKit ^2.60): the demo declares itself in `templates/base.html.twig` (aside items for the notes and the Storage / Move pages), the pages use the page header and section cards, the demo's own masthead and the inline styles on the notes are gone, a demo-platform e2e spec checks the layout rules, the drawer and axe, and the README screenshots no longer show the Symfony toolbar.
- Demo: no `private_entity_manager` line, and the notes page uploads through `ImageUploaderInterface::uploadImage()`.
- The bundle's Doctrine mapping no longer declares the `NowoStorageKit` alias: Doctrine ORM 3 has no entity namespace
  aliases, and DoctrineBundle 3.4 deprecates the option. Nothing changes for applications.

### Security

- 1.x support after 2.0.0 (P5): the 1.x line receives security fixes for 6 months after 2.0.0; fixes rated Moderate
  and above are backported to the last 1.x minor, Low ones are fixed in 2.x only. `.github/SECURITY.md` lists both lines.

## [1.8.1] - 2026-11-20

### Fixed

- **Missing foreign keys on MySQL / MariaDB** (audit XI-3). With doctrine/orm 3.7.0 to 3.8.0 and doctrine/dbal 4.5 or
  later, the schema generated for MySQL / MariaDB (`doctrine:schema:create` / `update`, `doctrine:migrations:diff`)
  lacked the foreign keys starting on the tables whose identifier columns get the exact collation:
  `UploaderCollationListener` rebuilt the table and handed it back with `setClassTable()`, and the ORM added its
  foreign keys to the table it had replaced. Affected: `storage_kit_file.profile_id` → `storage_kit_profile` from
  1.5.2 (a profile could be deleted under its files) and `storage_kit_file_reference.file_id` → `storage_kit_file`
  (ON DELETE CASCADE) from 1.5.3 (references of deleted files stayed behind). PostgreSQL and SQLite were not
  affected. With doctrine/orm before 3.7 and doctrine/dbal 4.5 or later, generating the schema failed instead
  (`setClassTable()` does not exist there). The listener now runs on `postGenerateSchema`, once every foreign key
  exists, and never calls `setClassTable()`: with doctrine/dbal 4.5+ and doctrine/orm 3.7+ it rebuilds each table with
  its editor (foreign keys and indexes included) and hands back the schema with `setSchema()`; otherwise it sets the
  column options in place. The platform and `server_version` are those of the schema tool's own entity manager, and a
  manager that does not map the bundle is left alone. The collations are unchanged.
  `UploaderCollationListener::postGenerateSchemaTable()` is kept as a deprecated no-op and its first constructor
  argument is no longer used. A test checks that the generated schema has the same foreign keys and indexes with and
  without the listener, and the database suites check both foreign keys and their rules on MySQL 8.4, MariaDB 11.4
  and PostgreSQL, in host and private (`doctrine.entity_manager`) mode. Existing databases:
  [UPGRADING](UPGRADING.md#to-181).

## [1.8.0] - 2026-11-20

The last 1.x minor before 2.0: without configuration it behaves as 1.7.1, and it warns about every default 2.0 flips.

### Added

- **`doctrine.private_entity_manager`** (REQ-ARCH-001 rule 6, zero-config): `true` makes every bundle read and write
  go through a private entity manager of the bundle's own (transaction-doctrine's `PrivateManager\PrivateEntityManager`),
  created on the connection, mapping and event manager of `doctrine.entity_manager`, else of your default entity
  manager. Same tables, same connection, no migration, no second manager to declare. The admin pages and console
  commands (profile save, test and switch, bucket moves, the purge, the retired-profile cleanup, the adoption) then
  never flush your pending changes, and a failed bundle write closes only the private manager. It is dropped at the
  start and end of every request, Messenger message and outermost console command (FrankenPHP worker mode with the
  kernel reset off included) and at `kernel.reset`, and replaced when closed. `false` keeps the 1.x behaviour until
  2.0, which defaults to `true` and removes `false`. How files are stored, delivered and authorised does not change.
- **Host-flush probe:** unless `private_entity_manager` is `true`, every flush of your default manager by the bundle first
  runs transaction-doctrine's read-only `HostFlushProbe`: each of your entity classes with pending changes that flush
  writes triggers one deprecation per request, message or command ("Your pending changes to App\X were flushed by
  NowoStorageKitBundle; 2.0 no longer does."). The deprecation log is the migration list (UPGRADING "To 1.8.0").

### Deprecated

- **Leaving `doctrine.private_entity_manager` unset, or setting it to `false`,** while the bundle entities live on
  your default manager (`entity_manager` null or naming the default manager): a build-time deprecation names the
  option, the 2.0 default (`true`; `false` is removed) and the line that opts in now. Two texts: one for unset, one
  for `false`.
- **`ImageUploader::upload()`** (the entity-returning upload, deprecated in 1.3) now triggers a runtime deprecation on
  every call; 2.0 removes it. Use `ImageUploaderInterface::uploadImage()`. `FileDelivery::respond()` keeps its 1.5
  runtime deprecation (removed in 2.0).
- **Relying on `nowo-tech/instance-settings-bundle` being installed by this package:** 2.0 moves it to `suggest`
  (announced in 1.3). Composer gives no notice; require it yourself when you use the settings-backed instance bucket.

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.9.1 (the private-manager kit and the host-flush probe, with the 1.9.1
  fixes; 1.8.1 fixed GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).
- The announced 2.0 default of the bundle's entity manager is now **the private manager on your default connection**
  instead of "a dedicated manager": no host has to declare a second Doctrine manager. `doctrine.entity_manager: <name>`
  stays as the option for a dedicated manager.

## [1.7.1] - 2026-11-19

### Fixed

- **Duplicate instance row inside your transaction on MySQL / MariaDB** (audit UH-SK-2; affected: 1.7.0, and every
  version since 1.4.0). The first use of the instance's `storage` section (or of a config profile's row) made inside a
  transaction you opened, for example an upload from your own write unit, could still create a second active row: the
  owner lock `GET_LOCK()` is session-scoped and was released before your commit, and the other request's re-read could
  not see the uncommitted row. The re-read under the owner lock is now a locking read (`SELECT … FOR UPDATE` on the
  instance's active rows), so the other request waits for your commit (at most `conflict_response.owner_lock_timeout`,
  then `CONFLICT_001`) instead of inserting a duplicate. A first use made outside any transaction then reuses the row.
  A first use made inside a transaction of its own on MySQL / MariaDB always fails with a retry-safe conflict and
  never reuses the row: its lookup before the owner lock already opened its snapshot (REPEATABLE READ), which cannot
  see the other request's commit; the retry in a new transaction reuses it. PostgreSQL under READ COMMITTED (the
  default) was not affected. Detection and recovery of rows created before:
  [UPGRADING](UPGRADING.md#duplicate-instance-rows-140161).
- **The PostgreSQL owner lock waits a bounded time** (audit UH-SK-3). `pg_advisory_xact_lock()` used to wait up to the
  session's `lock_timeout` (no limit by default). It now waits up to `conflict_response.owner_lock_timeout` (default 5
  seconds, 0 = no wait), like `GET_LOCK()` on MySQL / MariaDB, through a transaction-local `lock_timeout` set for the
  lock statement only; your previous value is restored right after it. A timeout answers `CONFLICT_001`.
- **`CONFLICT_002` without transaction-doctrine's bundle** (audit UH-SK-4). A lock conflict after part of the request
  had committed (a listener of `StorageAuditEvent`, a `sync://` handler of `RunStorageSyncMessage`) answered
  `CONFLICT_001` with `Retry-After` unless transaction-doctrine's bundle was registered with `commit_detection`. The
  bundle now counts the commits of its own connection with transaction-doctrine's commit-tracking DBAL middleware and
  takes its own commit mark at `kernel.request`. Only the connection of `doctrine.entity_manager` is wrapped, never your
  other connections; with transaction-doctrine's `commit_detection` on, its request mark is used and nothing is added.
  New option `conflict_response.commit_tracking` (`bundle`, the default, or `off`).
- Tests (audit UH-SK-7): two-kernel races of the first use on MySQL 8.4, MariaDB 11.4 and PostgreSQL (the row created
  inside an open transaction, the row committed just before the owner lock, an older snapshot, a config profile's
  row). `GET_LOCK()` is re-entrant within one session, so these need two connections.

## [1.7.0] - 2026-11-19

### Added

- Concurrency (REQ-RUNTIME-003 rule 6): a lock conflict, deadlock, lock wait timeout or serialization failure on one of the bundle's own routes answers **409** (`conflict_response.status: 503` for hosts that want it) with `X-Conflict-Code: CONFLICT_001` and `Retry-After` when nothing was committed, or `CONFLICT_002` without `Retry-After` when part of the request was (transaction-doctrine 1.6 request commit mark, a Messenger handler that already completed). The Storage / Move pages render it through your error page; the upload endpoint keeps `{error, message}` and adds `code`. Unique violations and lost connections are not mapped. New option `conflict_response` (`status`, `retry_after`, `owner_lock_timeout`).
- `docs/CONCURRENCY.md`: the write-path table (transaction, control, conflict answer), the codes, the host options and the residual risks.
- Two-connection race tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL (two first saves of a bucket, a cancel racing the copy runner, a lock wait on the switch, two saves of the same text reference) in the required "Lifecycle on …" CI job, plus a "Dependency floor (nowo-tech/transaction-* lowest, demo lock)" CI job and `.scripts/check-demo-lock.py`.
- `StorageEntityManager::transactional()`, `exclusively()` and `updateIf()` (write unit through transaction-doctrine's runner, owner lock, compare-and-set); requires `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6.1.

### Fixed

- Storage / Move pages: every write (save the bucket or the new bucket, start, switch, forget) is one transaction under a per-owner lock (MySQL / MariaDB `GET_LOCK`, PostgreSQL advisory lock) and reads the owner again inside it. Two first saves of the same owner could create two active buckets, two "start copying" clicks two open syncs; a save racing a switch could edit a retired bucket. The *Test connection* result is a DBAL update and no longer flushes the entity manager.
- Copy runner: a cancel committed while a slice ended was overwritten by the runner's "queued for the next slice" (or "completed"/"failed"), and a cancel could overwrite a sync that had just completed; status changes are now compare-and-set.
- First use of the instance `storage` section (and of a config profile): two first uploads at once could create two active instance rows; a later switch moved the files of the first one only. The row is now created once, under a lock. Affected: 1.4.0–1.6.1. Existing duplicates are not repaired automatically: see [UPGRADING, Duplicate instance rows](UPGRADING.md#duplicate-instance-rows-140161) for the detection queries and the recovery.
- Upload: a deadlock or lock wait timeout on the insert is rethrown (the objects written are deleted again) instead of being swallowed as a bucket failure (`503 {error: storage}`); the endpoint answers `409 CONFLICT_001`.
- The switch and `nowo:storage-kit:adopt-instance-bucket` no longer use `EntityManager::wrapInTransaction()`, which closed the default entity manager on any failure.

### Changed

- Demo: the shell is UiKit's admin layout with UiKit's default theme (header menu with the active item, UiKit flashes); Bootstrap 5, Bootstrap Icons and the CDN assets are gone, and the demo requires the latest `nowo-tech/ui-kit-bundle` (REQ-DEMO-014). The note form is a FormKit form (profile `demo`) themed by UiKit's form theme instead of Bootstrap's (REQ-DEMO-015).
- README: `## Screenshots` moved to the mid-body slot after `## Usage` (REQ-DOCS-019)
- Spec Kit: the six `src/` files added in 1.6.0 are mapped in `code-inventory.md` (REQ-SPECKIT-001)

## [1.6.1] - 2026-11-15

### Fixed

- **PostgreSQL with DBAL 3: file references could not be inserted (1.0.0–1.6.0)**: the reference insert (`FileReferences`,
  the only raw DBAL `INSERT` that left out an ORM-generated id; the entity inserts already take the id from the
  generator) failed with a NOT NULL violation where the ORM maps the id with a sequence (`AUTO` on PostgreSQL with
  DBAL 3, no column default). The statement now takes `nextval()` of that sequence itself; identity / autoincrement
  platforms (DBAL 4, MySQL, MariaDB, SQLite) are unchanged. New `StorageEntityManager::sequenceName()` (read-only).
  No behaviour or BC change otherwise; worker-safe (no state kept).

### Tests

- CI leg `PostgreSQL 17 (DBAL 3)` in the real-database job and `SequenceIdsTest` (fails on 1.6.0 there).

## [1.6.0] - 2026-11-15

### Added

- **Square images (profile pictures, logos)**: `Upload\SquareImageUploaderInterface::uploadSquareImage()` (implemented
  by `ImageUploader`) stores an image as WebP squares through the usual context / bucket / limit / audit flow. Checks
  of the new `Upload\SquareImageProcessor`: PNG, JPEG or still WebP by content only (SVG, GIF, HTML, animated WebP
  refused), `upload.square.max_bytes` (default 2 MiB) before anything is parsed, `upload.square.max_pixels` (default
  16 MP, never above `upload.max_pixels`) and the memory budget read from the header before decoding, JPEG turned
  upright, centred square re-encoded as WebP (metadata and any appended payload dropped) at `upload.square.size`
  (default 256 px) with `preview` (half) and `thumb` (quarter) squares, served by the existing download route. Needs GD
  with WebP: without it every square upload is refused with `off` (`SquareImageProcessor::supported()` says so
  beforehand), never an error.
- **Removal port** `Lifecycle\FileRemoverInterface` (`FileRemover`): `remove($uuid, $contextType, $contextId)` deletes a
  file's objects, references and row at once (left to the next purge when the bucket refuses), `expire()` leaves it to
  the next purge without a bucket call (for erasures inside a transaction). Matched by id AND exact context (a UUID
  learned elsewhere removes nothing; the context is compared on a DBAL read before the row is loaded); DBAL
  statements, no entity manager flushed. Both return a `Lifecycle\RemovedFile` (null when nothing matched). An expired
  file is no longer served: the download route answers 404, `FileDeliveryInterface::deliver()` throws
  `FileNotDeliverable` and `StoredImageResolver` skips it, from the expiry on and not only after the purge. New audit
  actions `file_removed` and `file_expired` (also for a removal whose bucket refused), recorded at once outside a
  transaction, else by `FileRemoverInterface::afterCommit(RemovedFile ...)` once the host committed.
- `StoredFile::isExpired()` and `StoredFile::EXPIRED_BEFORE`.
- `Context\SquareImageContextInterface`: a context that holds square images only; `uploadImage()` and the upload
  endpoint refuse it like an unknown context, so a GIF of 10 MiB cannot land where a 256 px square is expected.
- Configuration `upload.square.max_bytes`, `upload.square.max_pixels` and `upload.square.size`.

### Changed

- `ImageProcessor::upright()` is now a public static helper (shared with `SquareImageProcessor`; same behaviour).
- `UploadEndpoint::config()` answers `none` for a square-only context (the endpoint refuses its uploads anyway), so
  `nowo_storage_upload()` and `milkdownOptions()` offer no upload there.
- Files a personal-data erasure handed to the purge (`orphaned_since` 2000-01-01) are not served any more either:
  the download route answers 404 for them before the purge deletes them.

### Tests

- Square images: centred crop, sizes, EXIF orientation and metadata removal, PNG / JPEG polyglots (appended PHP, HTML,
  ZIP) neutralised, header-only decompression bombs and memory-budget refusals before decoding, SVG / GIF / HTML /
  animated WebP / mismatched magic refused; removal by exact context, inside and outside a host transaction, refusing
  bucket and unusable profile left to the purge; audit after commit; expired files answer 404; no square upload on GD
  without WebP; the endpoint offers nothing for square-only contexts; the square pixel cap.

## [1.5.3] - 2026-11-13

### Security

- **Look-alike context ids, source ids, owners and scopes were matched by the database collation (REQ-SEC-006;
  affects 1.0.0 to 1.5.2 on MySQL / MariaDB).** `storage_kit_file.context_id` / `owner`,
  `storage_kit_file_reference.source_id` and `storage_kit_profile.scope` used the host default collation, which folds
  case and accents and, on PAD SPACE collations, trailing spaces, and the bundle compared them in SQL only. With ids
  that differ only that way:
  - the scope `Acme` used the bucket profile of `acme`: its uploads went to that bucket, and its managers could open,
    edit, test, move and switch it from their Storage and Move pages;
  - saving or forgetting the text `abc` dropped the references of the text `ABC`, and the purge, finding one member
    of a family gone, forgot the references of all of them (their files became orphans and were purged after the
    retention);
  - the purge, finding the context `ABC` gone, could orphan the files of the context `abc`; a text could reference a
    file of a look-alike context;
  - `StoredFileRepository::bytesFor()` summed the usage of look-alike owners together (upload limits).

  Now every lookup, count, purge step and reference write filters with an exact SQL expression (`CAST(… AS BINARY)`
  on MySQL / MariaDB, `COLLATE BINARY` on SQLite) before any `LIMIT` and re-checks the rows with `===`; deletes and
  updates go by primary key; `ExistingIds::of()` returns only ids exactly among the asked ones; the four columns get
  the binary NO PAD collation (`utf8mb4_0900_bin`, MariaDB `utf8mb4_nopad_bin`) from the collation listener, which
  now logs a warning when it has to fall back to the PAD SPACE `utf8mb4_bin` (MySQL 5.7, MySQL older than 8.0.17, or a
  `server_version` without the patch number). Unusable owner keys and source ids (empty, too long, invalid UTF-8,
  edge white space or NBSP, control / zero-width / BOM / bidi characters), malformed contexts from a reference source
  and resolver scope keys outside the scope syntax are refused, never trimmed. While `source_id` still folds, a
  reference its folding unique key would reject is skipped with a logged warning naming the `ALTER` (never retried
  with the look-alike's row; the insert runs in a savepoint inside a host transaction, so a unique violation neither
  aborts that transaction on PostgreSQL nor closes an entity manager). See UPGRADING for the detection queries to run **before** the `ALTER`, and the `ALTER`
  (required for `storage_kit_file_reference`).

### Added

- `Identifier\ExactIdentifier` (internal): the shared byte-for-byte rules (`usable()`, `sql()`, `same()`);
  `PersonalData\UploaderIdentity::storable()` now delegates to it.

### Changed

- `RunStorageSyncHandler` carries `#[AsMessageHandler]` (REQ-MSG-001); the bundle keeps registering it with its
  explicit `messenger.message_handler` tag, once. Its retry and failure policy is documented (CONFIGURATION,
  Messenger).
- Spec Kit code inventory maps every file of `src/` again (REQ-SPECKIT-001 / 003).

### Tests

- `LookalikeIdentifiersTest`: `bob`, `Bob`, `bób`, `bob `, `bob\n`, NBSP and ZWSP look-alikes for every scenario, on
  the generated schema and on folding columns (SQLite `NOCASE` / `RTRIM`, MySQL / MariaDB `utf8mb4_unicode_ci` in the
  database CI jobs); a unique violation on a folding key leaves the entity manager open.

## [1.5.2] - 2026-11-13

### Security

- **Look-alike uploader identifiers reached another person's files (house rule LQ; affects 1.0.0 to 1.5.1 on MySQL /
  MariaDB).** `storage_kit_file.uploaded_by` / `uploaded_by_key` had no collation of their own, so they used the
  host default (`utf8mb4_0900_ai_ci`, `utf8mb4_unicode_ci`, `utf8mb4_general_ci`, MariaDB `utf8mb4_uca1400_ai_ci`),
  which folds case and accents and, except `utf8mb4_0900_ai_ci`, ignores trailing spaces. The GDPR lookups compared
  in SQL only: `export('alice')` also listed the files of `Alice`, `álice` (and `alice ` on a PAD SPACE collation),
  `erase('alice')` anonymised them and handed the unreferenced ones to the purge, and a key such as `App\User:abc`
  matched `App\User:ABC`. `nowo:storage-kit:backfill-uploader-keys` read one identifier per look-alike family: the
  others were skipped, or stamped with that identifier's key when the provider loaded it (a later keyed export /
  erasure of that user then included them). Now:
  - `StoredFileRepository::uploadedBy()` / `uploadedIdsBy()` match every row again with `===` (new
    `PersonalData\UploaderIdentity::matches()`); a page of look-alikes only no longer ends the walk early;
  - the backfill groups rows by their exact identifier, keys them by id, and does not use a user the provider returns
    under another identifier (counted as "loaded by the provider under another identifier");
  - an upload stores no `uploaded_by` / `uploaded_by_key` with leading or trailing white space, a control, format or
    line / paragraph separator character, invalid UTF-8 or more than 180 characters (`UploaderIdentity::storable()`;
    refused, never trimmed; an over-long identifier used to be cut to 180 characters, which could name another person);
  - new `Doctrine\UploaderCollationListener` gives both columns a binary NO PAD collation in the generated schema
    (MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+ `utf8mb4_0900_bin`, MySQL 5.7 `utf8mb4_bin`). Existing tables need an
    `ALTER TABLE`: see [UPGRADING](UPGRADING.md#to-152). PostgreSQL and SQLite compare exactly and were not affected.

### Fixed

- **Unit boundary tracker: per-worker message depth, request stack (audit NX CR1–CR3).** The tracker kept one
  process-wide "message in flight" flag and judged a main request outermost without looking at other main requests.
  A Messenger worker run in-process inside a message handler ended the outer message, so the inner worker's messages
  and a main request handled later by the outer handler cleared the outer unit (CR1); a main request handled
  in-process inside another main request cleared the outer one (CR2); a message skipped by a
  `WorkerMessageReceivedEvent` listener (`shouldHandle(false)`) stayed in flight until the worker stopped (CR3). It now
  keeps a stack of the running workers (`WorkerStartedEvent` / `WorkerRunningEvent` / `WorkerStoppedEvent`), each with
  its own message, and a stack of the main requests in flight, popped by their own `kernel.finish_request` /
  `kernel.terminate` (unbalanced events heal); a skipped message ends with its received event or at its worker's next
  `WorkerRunningEvent`. Message boundaries clear only outside another message. No configuration change.
- **Unit boundary tracker: aborted worker runs, skip read, nested terminate (audit OB).** A Messenger run aborted by an
  exception thrown out of `Worker::run()` (a throwing received, handled or running listener) left its worker "in
  flight", so inside a long-running command every later worker's messages stopped clearing (OB-M1) and the worker stack
  grew with every aborted run (OB-L1); the tracker now holds weak references to the worker and to the received event of
  the message in flight, and drops a run as soon as either is gone. The `shouldHandle(false)` read at the end of a
  received event no longer ends an outer message when a listener already ended the skipped one (OB-L2). The
  `kernel.terminate` of a main request handled in-process inside another one no longer clears the outer request nor
  resets the tracker (OB-L3). Note: in hosts with Messenger, DoctrineBundle's own subscriber clears every manager after
  each message, so a worker run inside a handler keeps the bundle's pending events and memos, not the identity map.
  Audit OB2-L1: a main request also drops the requests left in flight when the oldest one is not the kernel's main
  request (host code left the `RequestStack` unbalanced, then a request threw out of `HttpKernel::handle()`), so the
  next requests keep clearing instead of never clearing again for the rest of the worker's life.
- DoctrineBundle 3.4: bundle entities resolved to the default manager when a dedicated manager is configured.
  DoctrineBundle 3.4 `auto_discover_entities` (on by default for a manager without `mappings` and `auto_mapping`, with
  Symfony 7.3+ and doctrine/orm 3.6+) gives that manager's metadata driver chain a default attribute driver, which
  claims every `#[ORM\Entity]` class. With `doctrine.entity_manager` set, the host's manager then mapped the bundle
  entities too, and once it came first in the registry (e.g. another bundle prepending configuration for it)
  `getManagerForClass()` answered it: host-facing code going through `doctrine` (registry repositories,
  EntityType, UniqueEntity, MapEntity) queried its database instead of the bundle's. The compiler pass now also
  registers a driver that maps nothing for the bundle entity namespace, first in the chain of every other manager
  (`ExcludedEntityNamespaceDriver`, only the public `addDriver()` of the `doctrine.orm.<manager>_metadata_driver`
  chain), so the entities resolve to the configured manager on DoctrineBundle 2.x, 3.3 and 3.4+. The bundle's own
  services already used the configured manager. Affected in practice: 1.5.0 to 1.5.1 (every release with
  `doctrine.entity_manager`; both allow `doctrine/doctrine-bundle` `^3.0`) with DoctrineBundle 3.4.0 or later.

### Tests

- `tests/Integration/Lifecycle/LookalikeUploaderTest.php` (runs on the MySQL 8.4, MariaDB 11 and PostgreSQL CI jobs and
  on SQLite): export, erasure and backfill with `alice` / `Alice` / `alice ` / `álice` and look-alike keys, a page of
  look-alikes before the real files, a case-insensitive user provider, uploads with padded / zero-width / over-long
  identifiers, and the column collation on MySQL / MariaDB. Unit tests for `UploaderIdentity` and
  `UploaderCollationListener`.

## [1.5.1] - 2026-11-12

### Fixed

- **Console boundary counter could leak in a long-lived process (audit NM / NL2-v, REQ-RUNTIME-002).**
  `OwnEntityManagerSubscriber` (dedicated `doctrine.entity_manager`) and `RequestBoundaryListener` (request memos)
  counted running console commands with `++` on `console.command` and `--` on a late `console.terminate`. A
  terminate listener in between that threw or stopped the propagation, or a `console.error` listener that threw
  (Symfony then skips `console.terminate`), left the count above zero forever. In a kept-alive worker that ran a
  command in-process, the dedicated manager was then never recovered nor cleared again at the main request boundaries
  (managed rows and unflushed changes kept across requests and tenants), and no command end reset the memos any more.
  A `console.command` listener above the counter that stopped the propagation skipped the `++` while the `--` still
  ran, so a nested command cleared the outer unit mid-run.
  Every unit-of-work state change now happens at `PHP_INT_MAX` (new internal `UnitOfWorkFrames`): each console run is
  a frame keyed by its command and its input, saving the message flag and the main-request depth when pushed and
  restoring them when removed; `console.terminate` removes only its own frame (and the frames above it); `console.error`
  marks the frame, and a marked frame whose terminate never came is dropped at the next main request, received
  message, command or worker stop; the end of a main request or a message drops the frames started inside it. Main
  requests are counted and Messenger messages tracked, so the outermost unit is the one started while nothing else
  runs: a command run by a controller or a message handler, or a main request handled in-process inside another
  request or a message, no longer clears the outer unit (before, a command run by a controller cleared the dedicated
  manager mid-request). A top-level `kernel.terminate` (nothing else running) resets the main-request count, so a request whose `kernel.finish_request` never came (a throwing `kernel.exception` listener) does not stop the next clears (audit NR-b M1). The clears keep their priorities. `WorkerMessageSkipEvent` (dispatched by
  `messenger:failed:retry`) and `WorkerStoppedEvent` end the in-flight message.

### Tests

- `tests/Integration/Persistence/ConsoleBoundaryCounterKernelTest.php`: a real console `Application` on the kernel's
  own event dispatcher and the same kernel's `http_kernel`, no services resetter: throwing `console.terminate` /
  `console.error` listeners (also at `PHP_INT_MIN`), a self-recursive command whose inner run fails with a throwing
  error listener, an unknown nested command, a nested command whose `console.command` propagation is stopped above
  4096, a throwing `WorkerMessageReceivedEvent` listener inside an in-process consume command, a nested main request,
  a failed command inside a failed message followed by a worker stop, and the request-memo listener wired exactly as
  the kernel wires it. `tests/Support/BoundaryDriver.php` drives the unit tests through a real event dispatcher.

## [1.5.0] - 2026-11-12

Architecture bar W3 (REQ-ARCH-001 rules 1, 3 and 6; REQ-RUNTIME-002). Instance Settings stays in `require` until 2.0
(rule 8, announced in 1.3.0); `doctrine.entity_manager` keeps its `null` default in 1.x. See [UPGRADING](UPGRADING.md#to-150).

### Added

- **Dedicated entity manager** (rule 6): `doctrine.entity_manager` is supported. Set it to a manager of your own (same
  connection recommended, for example `storage_kit`): the bundle entities are mapped on it **only** (removed from the
  other managers' drivers, `auto_mapping` included; `StorageEntityManagerPass` fails the build on an unknown manager or
  one that does not map them), every bundle read and write uses it, and `Doctrine\OwnEntityManagerSubscriber` recovers
  it when a failed write closed it and clears it at the START of every unit (main `kernel.request`,
  `WorkerMessageReceivedEvent`, outermost `console.command`: nothing an abandoned unit left behind survives) and at
  its end (`kernel.terminate`, after every Messenger message, outermost `console.terminate`). Nested commands and
  requests handled in-process by a command do not clear the outer unit. The host's managers are never cleared. A
  bundle manager on another DBAL connection than the host's default one is reported at build time (a deprecation:
  the GDPR erasure, settings, uploads and references are then not atomic with the host's transactions). `null` (default) keeps the default manager in 1.x (2.0 changes the default). Recommended for new
  installs; the Flex recipe ships the line commented out.
- **Download port** (rule 3): `Download\FileDeliveryInterface::deliver(string $uuid, string $variant): DeliverableFile`,
  a framework-free VO (`redirect` / `stream`, status, headers, private max age, policy headers; the pre-signed URL and
  the bucket stream are kept in an internal `WeakMap`, out of every dump, cast, JSON and serialization; not cloneable
  nor serializable), `Download\FileDeliveryResponder` (HttpFoundation adapter, the 1.4 response) and
  `Download\FileNotDeliverable`. The download controller calls the port; alias or decorate the interface to serve files
  your way (`FileDeliveryPortsPass` fails the build when the service behind it does not implement it).
- **Ports for the documented services** (rule 1): `Reference\FileReferencesInterface` (`sync()`, `forget()`),
  `Download\StoredImageResolverInterface` (`resolve()`, `resolveText()`) and
  `PersonalData\StoredFilesPersonalDataInterface` (`export()`, `erase()` by plain strings), public aliases of
  `FileReferences`, `StoredImageResolver` and `StoredFilesPersonalData`.
- `StorageEntityManager::update()` / `updateRows()`: DBAL `UPDATE` of bundle rows that also aligns the instances the
  entity manager holds (no later flush rewrites them); encrypted (`#[Encrypted]`) fields are refused, read where the
  mapping declares the property, failing closed; ids are written 1,000 per statement (`UPDATE_CHUNK`).
- `StorageEntityManager::atomically()`: the bundle's DBAL writes run as one unit — a savepoint inside the host's
  transaction (a failing statement rolls back only the bundle's change, so the host can still commit its own work,
  PostgreSQL included), a transaction of its own otherwise. Each erasure page (anonymise + hand to the purge) is one
  unit.
- Tests: v1.4.0 equivalence (downloads through both controllers and services: status, headers, bytes; erasure and
  settings listener: every column of the tables) against literal copies under `tests/Support/Legacy/`; dedicated-manager
  kernel test (no admin or CLI write flushes the host manager); DDD host on the ports only; kept-alive worker test
  (1,200 downloads, two users, bundle manager empty after each request, flat memory); `lint:container` for the bundle
  ports, a host port, a 1.4 decorator, a dual decorator and a DDD host.

### Changed

- **GDPR erasure and the `storage` settings listener write with DBAL, never flush** (rule 6): `StoredFilesPersonalData::erase()`
  pages the matching ids (same match and keyset as before, no entity loaded) and updates them; the listener forgets
  the instance profile's test result with an `UPDATE` of its three columns. Same rows as 1.4; what the host left
  pending is not committed any more.
- `RequestBoundaryListener` also clears the request memos at the end of the outermost console command (a console
  depth counter: a command run in-process by another one is not a boundary).
- `symfony/deprecation-contracts` is required explicitly.

### Deprecated

- `FileDelivery::respond()` (removed in 2.0): use `FileDeliveryInterface::deliver()` and `FileDeliveryResponder`. It
  still returns the same response and triggers a deprecation.

## [1.4.0] - 2026-11-11

Named buckets from a host provider port without Instance Settings; worker-safe per-viewer read memo. See [UPGRADING](https://github.com/nowo-tech/StorageKitBundle/blob/v1.4.0/docs/UPGRADING.md#to-140).

Architecture bar W2 (standard Symfony by default, strict DDD opt-in): rule 8 (configuration through provider ports,
no Instance Settings needed), plus a rule 6 and a worker-mode fix found on the way.

### Added

- `Profile\ConfigProfileProviderInterface` (`names()`, `has()`, `delivery()`, `settings()`), public alias of
  `ConfigProfiles` (the `profiles` tree), and the `config_profile_provider` option: a host service that supplies the
  named buckets (secret manager, its own tenant configuration). `instance_profile` may then name one of them (checked
  at runtime: an unknown name turns uploads off instead of failing the build); `profiles` and `config_profile_provider`
  are exclusive. With `instance_profile` set, `NowoInstanceSettingsBundle` does not have to be registered
  (kernel test: no Instance Settings, buckets from the host).
- `EventListener\RequestBoundaryListener`: clears the request memos of `StorageProfiles`, `FileStorage`,
  `FileContexts` and `FileReferenceListener` at the start of every main request, at `kernel.terminate` and after every
  Messenger message, handled or failed (`WorkerMessageHandledEvent`, `WorkerMessageFailedEvent`). Its public `reset()`
  is the boundary for long-running host commands (call it per item).

### Security

- **Worker mode without the services resetter** (FrankenPHP `FRANKENPHP_RESET_KERNEL=false`): the read decisions of
  `FileContexts` were memoised per viewer identifier until `kernel.reset`, so in a kept-alive worker that does not run
  the resetter a read permission the application revoked was still honoured for that viewer, a context the application
  un-published stayed readable for anonymous visitors, and two users sharing an identifier (another firewall or user
  provider) shared decisions (memos also grew with every viewer / bucket). They are now cleared at the request
  boundary. Affected: 1.0.0–1.3.0. Advisory: GitHub Security Advisory (Low) for >= 1.0.0, < 1.4.0.

### Fixed

- **Read decisions are memoised per viewer object, not per identifier** (audit LR-S-L1): within one request, Messenger
  message or command, two users that share `getUserIdentifier()` (or two viewer objects that reuse an object id) no
  longer share a decision; `FileContexts` keeps a `WeakMap` keyed by the viewer instance (plus one memo for anonymous
  visitors).
- **The first upload to a config-pinned or settings-backed instance bucket no longer flushes the application's entity
  manager.** The profile row created on first use (`StorageProfiles::configRow()` / the `storage` section row) was
  persisted and flushed on the bundle's entity manager (the default one), committing whatever the application had
  pending; it is now a DBAL insert (`StorageEntityManager::insert()`, savepoint inside an open transaction) read back
  managed.

### Changed

- `StorageProfiles` and `StorageCspSources` take a `ConfigProfileProviderInterface` (`ConfigProfiles` implements it).
- Standard hosts: no configuration change, same defaults. `nowo-tech/instance-settings-bundle` stays in `require`
  until 2.0.

## [1.3.0] - 2026-11-11

Uploads write their row with a DBAL insert and never flush the host's entity manager; new ImageUploaderInterface with a StoredFileView DTO; InstanceSettings becomes optional. See [UPGRADING](https://github.com/nowo-tech/StorageKitBundle/blob/v1.3.0/docs/UPGRADING.md#to-130).

### Fixed

- **An upload no longer flushes the application's entity manager.** `ImageUploader` persisted the row and flushed the
  bundle's entity manager (the default one with `doctrine.entity_manager: null`), committing every change the
  application had left pending. The row is now written with a DBAL insert built from the entity metadata
  (`StorageEntityManager::insert()`), in a savepoint inside an open transaction (id read before the release; a failed
  insert rolls back only the savepoint and rethrows the original error, even when that rollback fails too). The DBAL
  insert runs no ORM lifecycle events (`prePersist` / `postPersist`, host Doctrine listeners) for new `StoredFile`
  rows; `StoredFile` has no callbacks of its own. `upload()` still returns the managed row.

### Added

- `Upload\ImageUploaderInterface` (public alias of `ImageUploader`): `uploadImage()` returns the `StoredFileView`
  DTO; inside a host transaction it dispatches nothing and the host calls `afterCommit($view)` after its commit
  (the `file_uploaded` audit event follows the commit). The upload endpoint uses it.

### Changed

- `StorageSettingsController::SETTINGS_SECTION_ROUTE` is the literal route name (no InstanceSettings class constant),
  and a settings-backed instance bucket without `nowo-tech/instance-settings-bundle` installed fails the build with a
  clear message.

### Deprecated

- The `StoredFile` entity return of `ImageUploader::upload()`: use `ImageUploaderInterface::uploadImage()` (changes in
  2.0).
- Relying on this package to install `nowo-tech/instance-settings-bundle`: only the settings-backed instance bucket
  needs it; require it in your application. It moves to `suggest` in 2.0.

## [1.2.0] - 2026-11-10

GDPR export / erasure keep finding a user's files after their identifier changes (stable, class-qualified uploader key; backfill command). See [UPGRADING](https://github.com/nowo-tech/StorageKitBundle/blob/v1.2.0/docs/UPGRADING.md#to-120).

### Security

- **Personal data after an identifier change** (DDD audit 2026-11-10, G2): uploads were linked to the person only by
  `getUserIdentifier()` (often an e-mail), so after an e-mail change `StoredFilesPersonalData::export()` / `erase()`
  missed every file uploaded under the old value. Each upload now also stores a stable uploader key
  (`storage_kit_file.uploaded_by_key`, indexed `idx_storage_kit_file_uploader_key`): `<real user class>:<id>` by
  default (`PersonalData\UserIdUploaderKeyResolver`; Doctrine proxies stripped, so two user classes with the same id
  never share a key; `sha256:<hex>` above 180 characters or for an anonymous class; no key and a one-time warning for users without a usable public `getId()`), or your
  `personal_data.uploader_key_resolver` (`PersonalData\UploaderKeyResolverInterface`). The new
  `exportUser(UserInterface)` / `eraseUser(UserInterface)` match that key, plus files without a key under the current
  identifier; a file stored with another key is never matched by identifier, so a re-used identifier no longer reaches
  the former owner's files **stored with a key**. Until the backfill, and through `export($identifier)` /
  `erase($identifier)` without a key, files are matched by identifier as in 1.1. Tenants sharing one user class with
  overlapping ids need a tenant-qualified resolver. Erasure clears the key too. **Schema
  change** (one nullable column and an index); files uploaded before this version need the one-off backfill
  (`nowo:storage-kit:backfill-uploader-keys`, see UPGRADING).

### Added

- `PersonalData\UploaderKeyResolverInterface`, `PersonalData\UserIdUploaderKeyResolver`, configuration
  `personal_data.uploader_key_resolver` and `personal_data.user_provider`, console command
  `nowo:storage-kit:backfill-uploader-keys` (loads each stored identifier through the user provider and asks the
  configured resolver, so it is exact for integer, UUID and ULID ids; idempotent, batched, `--dry-run`, `--skip`;
  files older than the user's `getCreatedAt()` stay without key), `StoredFile::getUploadedByKey()` / `setUploadedByKey()`,
  `StoredFilesPersonalData::exportUser()` / `eraseUser()`, optional `$userKey` on `export()` / `erase()` and
  `StoredFileRepository::uploadedBy()`.

### Changed

- Docs: USAGE gains "Using it from a DDD / hexagonal application"; Personal data, CONFIGURATION, SECURITY and
  ARCHITECTURE describe the uploader key.

## [1.1.0] - 2026-11-03

Import the AWS access-key CSV in the S3 profile form

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
