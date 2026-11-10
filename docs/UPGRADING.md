# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.2.0](#to-120)
- [To 1.1.0](#to-110)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Install and configure](#install-and-configure)
  - [Migrating from an application's own storage code](#migrating-from-an-applications-own-storage-code)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.2.0

From **1.1.x**:

```bash
composer update nowo-tech/storage-kit-bundle
```

**Schema change** (one nullable column, one index), no breaking API change.

Why: uploads were linked to the person only by `getUserIdentifier()`, often an e-mail. After an e-mail change, the
GDPR export and erasure (`StoredFilesPersonalData::export()` / `erase()` with the new identifier) missed every file
uploaded under the old one. Each upload now also stores a stable **uploader key** (`uploaded_by_key`), the user's
`getId()` by default.

1. Migrate the schema (`doctrine:migrations:diff` then `migrate`), or by hand (add your `doctrine.table_prefix`):

   ```sql
   -- MySQL / MariaDB
   ALTER TABLE storage_kit_file ADD uploaded_by_key VARCHAR(180) DEFAULT NULL;
   CREATE INDEX idx_storage_kit_file_uploader_key ON storage_kit_file (uploaded_by_key);
   -- PostgreSQL / SQLite: the same statements (VARCHAR(180) DEFAULT NULL)
   ```

2. **Backfill** the key of the files uploaded before 1.2.0 once, **before** any further identifier change, from your
   user table (adapt the table and column names; the key must be what your resolver returns, the plain id by default):

   ```sql
   -- MySQL / MariaDB
   UPDATE storage_kit_file f
     JOIN app_user u ON u.email = f.uploaded_by
      SET f.uploaded_by_key = CAST(u.id AS CHAR)
    WHERE f.uploaded_by_key IS NULL AND f.uploaded_by IS NOT NULL;

   -- PostgreSQL / SQLite
   UPDATE storage_kit_file
      SET uploaded_by_key = (SELECT CAST(u.id AS VARCHAR(180)) FROM app_user u WHERE u.email = storage_kit_file.uploaded_by)
    WHERE uploaded_by_key IS NULL AND uploaded_by IS NOT NULL;
   ```

   Files whose uploader already changed identifier before this backfill cannot be matched by SQL: if you keep a log
   of identifier changes, run the same update with the old value, or call `export()` / `erase()` once per past
   identifier of the person. Rows left without a key keep working as before (matched by identifier).

3. Call `exportUser($user)` / `eraseUser($user)` (or `export($identifier, $key)` / `erase($identifier, $key)`) from
   your GDPR export provider and eraser. `export($identifier)` / `erase($identifier)` without a key behave exactly as
   in 1.1 (identifier only).

4. If `getId()` is not what identifies a person (several user classes sharing ids, users re-created on login, no
   `getId()`), set `personal_data.uploader_key_resolver` to a service implementing
   `PersonalData\UploaderKeyResolverInterface` (and backfill with what it returns).

Behaviour changes:

- New uploads by users with a usable `getId()` (an integer, a non-empty string or a `Uuid`, at most 180 characters)
  store it in `uploaded_by_key`. Users without one (for example `InMemoryUser`) store no key, as before.
- With a key, a file stored with **another** key is no longer matched by identifier: if an e-mail was given to a new
  person, `exportUser()` / `eraseUser()` of that person do not reach the former owner's files.
- Erasure also clears `uploaded_by_key`.
- `ImageUploader` and `StoredFilesPersonalData` take an optional last constructor argument
  (`UploaderKeyResolverInterface`, default `UserIdUploaderKeyResolver`); code that builds them by hand keeps working.

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
