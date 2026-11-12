# Upgrading

## Table of contents

- [Planned for 2.0](#planned-for-20)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.0](#to-110)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Install and configure](#install-and-configure)
  - [Migrating from an application's own storage code](#migrating-from-an-applications-own-storage-code)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Planned for 2.0

Breaking changes announced in 1.x (deprecated now, applied in the next major per REQ-BC-001):

1. **`nowo-tech/instance-settings-bundle` moves from `require` to `suggest`** (announced in 1.3.0; REQ-ARCH-001
   rule 8). With the settings-backed instance bucket (`instance_profile: null`), require it in your own
   `composer.json` now; a config-pinned bucket does not need it.
2. **`doctrine.entity_manager` defaults to a dedicated manager** instead of `null` (the default manager). Declare it
   now ([To 1.5.0](#to-150), step 2) or set `entity_manager: null` explicitly to keep the 1.x behaviour.
3. **`FileDelivery::respond()` is removed** (deprecated in 1.5.0): use `FileDeliveryInterface` +
   `FileDeliveryResponder`.
4. **`ImageUploader::upload()` returning the `StoredFile` entity is removed** (deprecated in 1.3.0): use
   `uploadImage()`.

## To 1.5.1

Target **1.5.1**, from **1.5.0**: `composer update nowo-tech/storage-kit-bundle`. Patch release, no configuration
change, no migration. The bundle's boundary listeners now track every console run, Messenger message and main request
at `PHP_INT_MAX` (audit NM / NL2-v), so a host `console.terminate` / `console.error` listener that throws or stops the
propagation can no longer stop the dedicated entity manager and the request memos from being cleared. Edge-case
behaviour change: a command run in-process by a controller or a message handler, or a main request handled inside
another request or a message, no longer clears the outer unit's dedicated manager (it is cleared when the outer unit
ends). `RequestBoundaryListener::reset()` stays the public API for long-running commands. Nothing to do in host code.

## To 1.5.0

Target **1.5.0**, from **1.4.x**: `composer update nowo-tech/storage-kit-bundle`. Architecture bar W3 (REQ-ARCH-001
rules 1, 3 and 6): no schema change, no dependency removed, same routes, same responses.

1. **GDPR erasure and the `storage` settings listener do not flush any more.** `StoredFilesPersonalData::erase()` /
   `eraseUser()` and the `SettingsChangedEvent` listener now write their rows with DBAL `UPDATE` statements: the rows
   end exactly as before (same columns, same values), but whatever **your** code left pending in the entity manager is
   no longer committed by them. If some code relied on an erasure or a settings save to flush its own changes, flush
   them yourself. Inside your transaction the statements join it, as before.
2. **Recommended for new installs: a dedicated entity manager.** `doctrine.entity_manager` is supported now. Point it
   at a manager on the **same connection** as your default one:

   ```yaml
   # config/packages/doctrine.yaml
   doctrine:
       orm:
           default_entity_manager: default
           entity_managers:
               default:
                   connection: default
                   # your mappings / auto_mapping as before
               storage_kit:
                   connection: default
   # config/packages/nowo_storage_kit.yaml (the Flex recipe ships this line commented out)
   nowo_storage_kit:
       doctrine:
           entity_manager: storage_kit
   ```

   The bundle entities are then mapped on that manager only (also removed from `auto_mapping`), the admin and CLI
   writes that still flush (profile save and test, bucket moves, purge, retired-profile cleanup, adoption) flush that
   manager only, and the bundle clears it at the end of every request, Messenger message and console command.
   Existing installs keep `null` (the default manager) unchanged — it stays the default in 1.x, and 2.0 changes the
   default to a dedicated manager ([Planned for 2.0](#planned-for-20)); switching later needs no migration (same tables), but
   your own code must not hold Doctrine relations to the bundle entities (reference files by `uuid`, as documented)
   and must read them through the bundle's repositories or `doctrine.orm.storage_kit_entity_manager`.
3. **Deprecated: `FileDelivery::respond()`** (removed in 2.0). Call the port and the adapter instead; the response is
   the same (status, headers, bytes):

   ```php
   // before
   $response = $fileDelivery->respond($file, 'preview');
   // after: FileDeliveryInterface (by public id, framework-free) + the HttpFoundation adapter
   $response = $responder->toResponse($delivery->deliver($file->getUuid(), 'preview'));
   ```

   `deliver()` throws `Download\FileNotDeliverable` where `respond()` threw a `NotFoundHttpException`. The download
   controller now calls `FileDeliveryInterface`: alias or decorate that interface to change how files are served. A
   decorator of the `FileDelivery` service id keeps decorating that id for code that calls it, but the controller no
   longer goes through it (in 1.4 it required the final class, so no decorator could run there).
4. **New ports (optional).** Type-hint `Reference\FileReferencesInterface`, `Download\StoredImageResolverInterface`,
   `PersonalData\StoredFilesPersonalDataInterface` and `Download\FileDeliveryInterface` instead of the concrete
   classes; all four are public service aliases.
5. **Worker mode.** The request memos are now also cleared at `console.terminate`; nothing to configure.

## To 1.4.0

Target **1.4.0**, from **1.3.0**: `composer update nowo-tech/storage-kit-bundle`. **Standard hosts: nothing to
change** (same services, same defaults; no schema change).

1. **Named buckets from your own service (optional, DDD hosts).** Implement
   `Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface` and set
   `nowo_storage_kit.config_profile_provider` to its service id (remove `profiles`: the two are exclusive). Pin the
   instance bucket to one of its names with `instance_profile`; `NowoInstanceSettingsBundle` can then stay out of
   `config/bundles.php`. A name must keep meaning the same bucket (files point at it); the provider is asked on every
   use, so keep no request state in it.
2. **No flush on the first upload.** The profile row created by the first upload to a config-pinned or settings-backed
   instance bucket is a DBAL insert: if some code relied on that upload to flush its own pending entities, flush them
   yourself.
3. **Worker mode.** The bundle clears its request memos itself at the request boundary; nothing to configure.
4. Code that constructs `StorageProfiles` or `StorageCspSources` itself keeps working (`ConfigProfiles` implements the
   new interface).

## To 1.3.0

Target **1.3.0**, from **1.2.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

No configuration change.

1. **Uploads do not flush your entity manager any more.** If some code relied on an upload to persist its own pending
   entities, flush them yourself.
   The new row is a DBAL insert: Doctrine lifecycle events (`prePersist`, `postPersist`, your own Doctrine listeners)
   no longer fire for new `StoredFile` rows; listen to the `file_uploaded` `StorageAuditEvent` instead.
2. **Deprecated: `ImageUploader::upload()` returning the entity.** Inject `ImageUploaderInterface` and call
   `uploadImage()` (a `StoredFileView`; reference the file by `uuid`). Inside your own transaction, call
   `afterCommit($view)` after the commit to record the audit event.
3. **Deprecated: the implicit InstanceSettings install.** With the default settings-backed instance bucket
   (`instance_profile: null`) add `nowo-tech/instance-settings-bundle` to your own `composer.json`; 2.0 moves it to
   `suggest`. A config-pinned bucket (`instance_profile: <name>`) does not need it.

## To 1.2.0

From **1.1.x**:

```bash
composer update nowo-tech/storage-kit-bundle
```

**Schema change** (one nullable column, one index), no breaking API change.

Why: uploads were linked to the person only by `getUserIdentifier()`, often an e-mail. After an e-mail change, the
GDPR export and erasure (`StoredFilesPersonalData::export()` / `erase()` with the new identifier) missed every file
uploaded under the old one; and after an e-mail was given to someone else, the new holder's export / erasure reached
the former owner's files. Each upload now also stores a stable **uploader key** (`uploaded_by_key`), by default
`<user class>:<id>` (for example `App\Entity\Customer:5`).

1. Migrate the schema (`doctrine:migrations:diff` then `migrate`), or by hand:

   ```sql
   -- MySQL / MariaDB / PostgreSQL / SQLite
   ALTER TABLE storage_kit_file ADD uploaded_by_key VARCHAR(180) DEFAULT NULL;
   CREATE INDEX idx_storage_kit_file_uploader_key ON storage_kit_file (uploaded_by_key);
   ```

   With `doctrine.table_prefix`, both the table **and the index** names get the prefix
   (`app_storage_kit_file`, `app_idx_storage_kit_file_uploader_key`), as `migrations:diff` generates them.

2. **Backfill** the key of the files uploaded before 1.2.0 once, **before** any further identifier change:

   ```bash
   php bin/console nowo:storage-kit:backfill-uploader-keys --dry-run -v   # review identifier → key
   php bin/console nowo:storage-kit:backfill-uploader-keys
   ```

   The command loads each stored identifier through `personal_data.user_provider` (default `security.user_providers`,
   your firewall user providers) and stores what the configured resolver returns, so it is exact for every id type
   (integer, UUID, ULID) and for a custom resolver. It is idempotent (only rows without a key), batched (`--batch`,
   default 200 identifiers) and skips identifiers the provider no longer knows and users without a key.

   **Reassigned identifiers.** The user is loaded by the identifier as it is **now**. If an identifier (e-mail) was
   given to another person after some uploads, the backfill would stamp the former owner's files with the new
   holder's key and make them the new holder's for export / erasure. When the user object has a public
   `getCreatedAt()` returning a date, files created before it are left without key (counted as "files older than
   their user"). Otherwise, leave such identifiers out (`--skip=<identifier>`, repeatable) and handle them by hand
   (`export()` / `erase()` per identifier, or an `UPDATE` limited to rows created after the new holder got the
   identifier). The same holds for an identifier that several user providers know (for example the same e-mail as
   an admin and as a customer): with a provider chain such as `security.user_providers` it is attributed to the first
   provider's user, so `--skip` it and handle those rows by hand. On a case-insensitive collation (MySQL / MariaDB
   `_ci`), case variants of an identifier are processed as one value; with a case-sensitive user provider, rows whose
   variant the provider does not know stay without key (identifier-only, as in 1.1).

   SQL alternative, **integer ids only** and the default resolver (the key is `<class>:<id>`; mind backslash
   escaping in MySQL string literals; exclude reassigned identifiers the same way):

   ```sql
   -- MySQL / MariaDB
   UPDATE storage_kit_file f
     JOIN app_user u ON u.email = f.uploaded_by AND f.created_at >= u.created_at
      SET f.uploaded_by_key = CONCAT('App\\Entity\\User:', u.id)
    WHERE f.uploaded_by_key IS NULL AND f.uploaded_by IS NOT NULL;
   -- PostgreSQL / SQLite
   UPDATE storage_kit_file
      SET uploaded_by_key = 'App\Entity\User:' || (SELECT u.id FROM app_user u
          WHERE u.email = storage_kit_file.uploaded_by AND storage_kit_file.created_at >= u.created_at)
    WHERE uploaded_by_key IS NULL AND uploaded_by IS NOT NULL
      AND EXISTS (SELECT 1 FROM app_user u WHERE u.email = storage_kit_file.uploaded_by);
   ```

   Do **not** use SQL for UUID / ULID ids: they are stored as `BINARY(16)` / BLOB (MySQL, MariaDB, SQLite) or as
   RFC 4122 text for a ULID (PostgreSQL), never the string `getId()` returns, so every backfilled row would get a key
   that never matches and would leave the export / erasure entirely. The join column must be unique; its collation
   decides case sensitivity. Identifiers longer than 180 characters were cut at upload and never match.

   Files whose uploader already changed identifier before the backfill cannot be matched: run `export()` / `erase()`
   once per past identifier of the person. Rows left without a key keep working as in 1.1 (matched by identifier).

3. Call `exportUser($user)` / `eraseUser($user)` (or `export($identifier, $key)` / `erase($identifier, $key)`) from
   your GDPR export provider and eraser. `export($identifier)` / `erase($identifier)` without a key behave exactly as
   in 1.1 (identifier only, including other people's keyed rows under that identifier).

4. **The key must never change for a person.** If `<class>:<id>` is not what identifies a person — users of several
   tenants in separate tables sharing ids and class, users re-created on login, no public `getId()` — set
   `personal_data.uploader_key_resolver` to a service implementing `PersonalData\UploaderKeyResolverInterface`
   (for example `sprintf('%s:%s:%s', $user::class, $user->getTenantId(), $user->getId())`) **before** the backfill.
   Changing the resolver (or a user class name, or the id type) after uploads leaves rows whose old key nothing
   matches, and keyed rows are never matched by identifier, so they silently leave the export / erasure. Re-key the
   existing rows in the same deployment with an `UPDATE` that maps each old key to the new one, for example from the
   default `<class>:<id>` to a `tenant:<tenant>:<id>` key (adapt to your schema; integer ids):

   ```sql
   -- MySQL / MariaDB (backslashes doubled inside string literals)
   UPDATE storage_kit_file f
     JOIN app_user u ON f.uploaded_by_key = CONCAT('App\\Entity\\User:', u.id)
      SET f.uploaded_by_key = CONCAT('tenant:', u.tenant_id, ':', u.id);
   -- PostgreSQL
   UPDATE storage_kit_file f
      SET uploaded_by_key = 'tenant:' || u.tenant_id || ':' || u.id
     FROM app_user u
    WHERE f.uploaded_by_key = 'App\Entity\User:' || u.id;
   -- SQLite
   UPDATE storage_kit_file
      SET uploaded_by_key = (SELECT 'tenant:' || u.tenant_id || ':' || u.id FROM app_user u
          WHERE storage_kit_file.uploaded_by_key = 'App\Entity\User:' || u.id)
    WHERE EXISTS (SELECT 1 FROM app_user u WHERE storage_kit_file.uploaded_by_key = 'App\Entity\User:' || u.id);
   ```

   Only rows whose old key matches an existing user are touched: keys of other user classes and of deleted users stay
   as they are. Never re-key with a correlated subquery that is not guarded like this: a row without a match would get
   NULL and fall back to identifier-only matching.

   Clearing the keys and running the backfill again is **not** equivalent: rows whose uploader changed identifier
   since the upload are no longer found by the backfill and fall back to identifier-only matching.

Behaviour changes:

- New uploads by users with a public `getId()` without arguments (an integer, a non-empty string or a `Uuid` /
  `Ulid`) store `<real class>:<id>` (Doctrine proxies give the entity class; a key above 180 characters is stored as
  `sha256:<hex>`, as is a key with the NUL byte of an anonymous class name). Users without a usable id (`InMemoryUser`, a private or throwing `getId()`, an uninitialised id)
  store no key, as before, and a warning is logged once per user class.
- With a key, a file stored with **another** key is no longer matched by identifier. This protects re-used
  identifiers **only for keyed rows**: until the backfill, rows without a key (and every identifier-only call) are
  matched by identifier as in 1.1.
- Erasure also clears `uploaded_by_key`.
- `ImageUploader` and `StoredFilesPersonalData` take an optional last constructor argument
  (`UploaderKeyResolverInterface`, default `UserIdUploaderKeyResolver`); code that builds them by hand keeps working.
- New configuration `personal_data.uploader_key_resolver` (null) and `personal_data.user_provider`
  (`security.user_providers`).

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

No breaking change, no configuration or schema change.

- The Amazon S3 bucket forms gain an optional file row **Import from AWS CSV** (`<form>[access_key_csv]`, unmapped
  `FileType`): the scope Storage page (`storage_profile`), the Move target (`storage_target`) and the `storage`
  Instance Settings section (`settings_section`, added by a form type extension on Instance Settings'
  `SettingsSectionType`). The forms are now `multipart/form-data` (`form_start` sets it). Templates that render the
  forms with `form_start` / `form_row` / `form_end` need no change; a template that renders fields one by one must add
  `form_row(form.access_key_csv)` or the row is left out (the form still works without it).
- Hosts with a request size limit in front of PHP (reverse proxy, `post_max_size`, `upload_max_filesize`) need room
  for a few KB; the kit refuses files above 4 KB itself.
- Amazon S3 keys from the CSV must be long-term IAM user keys (`AKIA…`); temporary STS keys (`ASIA…`) are refused
  (`profile.error.csv_temporary`).
- Docs: the configuration `profiles` / `STORAGE_KIT_DSN` path is now documented as **ops-only** (infrastructure buckets
  managed outside the application). Behaviour is unchanged and nothing is deprecated; the primary path stays the
  encrypted database settings.
- New translation keys `profile.field.access_key_csv`, `profile.help.access_key_csv` and `profile.error.csv_*`;
  hosts that override the `NowoStorageKitBundle` catalogue should add them.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6` with `ext-gd`; Symfony 7.4 / 8.x; Doctrine ORM 3.3+.
- `nowo-tech/doctrine-encrypt-bundle` ^2.10 registered and configured (the container refuses to compile without it).
- `nowo-tech/instance-settings-bundle` **^1.3.1** registered, unless the instance bucket comes from the configuration
  (`instance_profile`).
- `nowo-tech/ui-kit-bundle` **^2.23** (the `visible-when` peer) and `nowo-tech/form-kit-bundle` ^2.17.
- An S3-compatible bucket (there is no local-disk provider).

### Install and configure

1. `composer require nowo-tech/storage-kit-bundle` (Flex registers the bundle and adds
   `config/packages/nowo_storage_kit.yaml` and `config/routes/nowo_storage_kit.yaml`).
2. Import the routes (opt-in): the `nowo_storage_kit` loader and the `nowo_instance_settings` loader
   ([INSTALLATION](INSTALLATION.md#routes)).
3. Register the UiKit `visible-when` Stimulus peer in your Stimulus app
   ([INSTALLATION](INSTALLATION.md#visible-when-stimulus-peer)); without it the endpoint rows stay visible for
   Amazon S3.
4. Generate a migration for `storage_kit_profile`, `storage_kit_file`, `storage_kit_file_reference` and
   `storage_kit_sync` (with your `doctrine.table_prefix`).
5. Add `access_control` rules for `/admin/storage` (and `/admin/settings`); keep uploads behind a firewall.
6. Implement your `FileContextInterface` services (who writes / reads) and `FileReferenceSourceInterface` services
   (texts that point at files) — [USAGE](USAGE.md#file-contexts-who-may-write-and-read).
7. Schedule `php bin/console nowo:storage-kit:purge` daily (cron or Symfony Scheduler). After a bucket move, run
   `php bin/console nowo:storage-kit:forget-retired` (or *Forget previous buckets*) once no file uses the old bucket,
   so its encrypted credentials do not stay around.
8. With Messenger, route `Nowo\StorageKitBundle\Message\RunStorageSyncMessage` to a worker transport; without it, bucket
   moves run with `nowo:storage-kit:sync`.
9. Open **Settings → Storage**, choose a provider, save and press **Test connection**.

### Migrating from an application's own storage code

Applications that kept their own storage profiles, stored files and references (for example symfony-beacon before
spec 183 moved to the kit):

1. **Keep the URIs.** Set `uri_scheme` to the scheme your texts already contain (`beacon-file` gives
   `![alt](beacon-file:<uuid>)`), so no text has to be rewritten. Download paths under `upload.path_prefix` are
   recognised too.
2. **Import the rows** into the kit tables in a migration: profiles into `storage_kit_profile` (keep `uuid`, `scope`,
   `state`; set `source = 'database'`; the secret column must hold a value DoctrineEncrypt can decrypt — re-encrypt it
   with the same key or save it again through the entity), files into `storage_kit_file` (keep `uuid`, `storage_key`,
   `sha256`, `variants`, `context_type` / `context_id`, `orphaned_since`), references into
   `storage_kit_file_reference`. Objects stay where they are: the kit reads the same keys from the same buckets.
3. **Move the instance bucket into the `storage` section** once the rows are in:

   ```bash
   php bin/console nowo:storage-kit:adopt-instance-bucket
   ```

   It takes the instance's active `database` row, writes its connection into the `storage` Instance Settings section
   (the secret becomes an Instance Settings secret) and turns the row into the settings-backed active profile, so the
   files keep pointing at it. Until it runs, the imported row stays the instance bucket and the section's values are
   not used. Scope buckets stay `database` rows (scope Storage pages).
4. **Replace your services** with the kit's: contexts → `FileContextInterface`, reference tracking →
   `FileReferenceSourceInterface`, upload / download controllers → the kit routes (update editors to
   `nowo_storage_milkdown_upload()` / `nowo_storage_upload()`), Markdown image resolution → `nowo_storage_images()` /
   `StoredImageResolver`, CSP provider → `StorageCspSourceProvider`, purge and sync commands → `nowo:storage-kit:purge` /
   `nowo:storage-kit:sync`, quota → `UploadLimitInterface`, scope pages → `scopes.*`, audit → listen to
   `StorageAuditEvent`. Then drop the old tables.
5. **Check**: `php bin/console nowo:storage-kit:sync <uuid> <uuid> --check` compares a bucket with its rows (missing and
   unknown objects).

### Template contract

The Storage and Move pages are UiKit form scaffolds (`settings/edit.html.twig`, `move/edit.html.twig`). Override them
with `web_ui.templates.settings` / `web_ui.templates.move` and a thin template that extends
`@!NowoStorageKitBundle/…` ([TEMPLATES](TEMPLATES.md)).

### Breaking changes

None (first release).
