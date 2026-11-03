# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Install and configure](#install-and-configure)
  - [Migrating from an application's own storage code](#migrating-from-an-applications-own-storage-code)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

Nothing yet.

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
