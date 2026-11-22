# Upgrading

## Table of contents

- [Done in 2.0](#done-in-20)
- [Unreleased](#unreleased)
- [To 2.0.1](#to-201)
- [To 2.0.0](#to-200)
  - [Before you upgrade](#before-you-upgrade)
  - [The private entity manager is the only mode](#the-private-entity-manager-is-the-only-mode)
  - [Removed](#removed)
  - [Instance Settings is a suggest](#instance-settings-is-a-suggest)
  - [Private manager only](#private-manager-only)
  - [1.x support](#1x-support)
- [To 1.8.1](#to-181)
  - [Missing foreign keys on MySQL / MariaDB (1.5.2 to 1.8.0)](#missing-foreign-keys-on-mysql--mariadb-152-to-180)
- [To 1.8.0](#to-180)
  - [The private entity manager notice](#the-private-entity-manager-notice)
  - [Reading the host-flush probe](#reading-the-host-flush-probe)
  - [Opting in: what changes](#opting-in-what-changes)
  - [The other 2.0 notices](#the-other-20-notices)
  - [Preparing for 2.0](#preparing-for-20)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
  - [Duplicate instance rows (1.4.0–1.6.1)](#duplicate-instance-rows-140161)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.3](#to-153)
- [To 1.5.2](#to-152)
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

## Done in 2.0

The breaking changes 1.x announced (REQ-BC-001), applied in 2.0 ([To 2.0.0](#to-200)):

1. **`nowo-tech/instance-settings-bundle` moved from `require` to `suggest`** (announced in 1.3.0; REQ-ARCH-001
   rule 8).
2. **The bundle entities are written through a private entity manager on your default connection** (announced in
   1.8.0): `doctrine.private_entity_manager: false` is removed (a build error), `true` is a no-op;
   `doctrine.entity_manager: <name>` stays, for a dedicated manager.
3. **`FileDelivery::respond()` is removed** (deprecated in 1.5.0).
4. **`ImageUploader::upload()` returning the `StoredFile` entity is removed** (deprecated in 1.3.0).

## Unreleased

From **2.0.1**: `composer update nowo-tech/storage-kit-bundle`, then add the new column **in the same deploy as the
code** (the pages read and write `row_version`; without the column the Storage and Move pages fail).

`storage_kit_profile` gets `row_version` (REQ-RUNTIME-003 rule 4: the bucket forms refuse a page that is no longer
current instead of overwriting another admin's save). `php bin/console doctrine:migrations:diff` (or
`doctrine:schema:update --dump-sql`) produces it; the SQL is the same on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16,
an instant, additive change (existing rows start at version 1):

```sql
ALTER TABLE storage_kit_profile ADD row_version INT DEFAULT 1 NOT NULL;
```

With `doctrine.table_prefix`, use your prefixed table name.

What changes for you:

- **Admins:** a save from a page opened before someone else saved the same bucket shows "Someone else saved this
  bucket after you opened the page…" and writes nothing (409, `X-Error-Code: STORAGE_KIT_STALE_EDIT`). They reload
  the page and apply their change again.
- **Custom templates** (`web_ui.templates.settings` / `move`) that list the form fields one by one must also render
  the hidden `rowVersion` field (`form_rest()` does); without it every save of an existing bucket is refused as stale.
- **Your code** calling `StorageMove::saveTarget()` is unchanged: without the new fifth argument the save is not
  checked, but it advances the version, so a Move page opened before is refused. Pass the version the user saw to
  get the check (`StaleEditException` when it no longer matches). Direct ORM writes of your own to a
  `StorageProfile` do not advance the version.
- **The hidden token is `<row id>:<row version>`** (`StorageProfileType` options `row_id` + `row_version`), so a page
  built for another bucket row (one replaced since) is refused. Code of yours reading the submitted token uses
  `StorageProfileType::submittedRowVersion($form, $profile)`.

## To 2.0.1

From **2.0.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

From **2.0.0**: `composer update nowo-tech/storage-kit-bundle`. No configuration or code change.

If your code calls `StorageMove::saveTarget($target, $actor, $fields)` without the fourth argument on a target your
own manager loaded (or one kept across requests in a worker), 2.0.0 saved the stored row unchanged and still returned
`true`: save that target again after the update. The Move page was not affected.

## To 2.0.0

From **1.8.1**:

```bash
composer update nowo-tech/storage-kit-bundle
```

From **1.8.x**:

```bash
composer require nowo-tech/storage-kit-bundle:^2.0 -W
```

With the settings-backed instance bucket (`instance_profile: null`, the default and what the 1.0 recipe sets up),
require Instance Settings yourself first (see [Instance Settings is a suggest](#instance-settings-is-a-suggest)):

```bash
composer require nowo-tech/instance-settings-bundle:^1.3.1
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x
minor without deprecation notices from this bundle upgrades without code changes. After 2.0.0 is released, the 1.x
line receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor; Low
ones are fixed in 2.x only.

### Before you upgrade

On **1.8.1**, run your usual traffic, console commands and test suite with deprecations logged (the Symfony profiler,
the `deprecation` log channel, or PHPUnit's deprecation report) and clear every notice of this bundle:

1. The private entity manager notice at container build ([To 1.8.0](#the-private-entity-manager-notice)). Unset:

   > Not setting "nowo_storage_kit.doctrine.private_entity_manager" is deprecated: the storage admin pages and console
   > commands flush the application's default entity manager, including its own pending changes. 2.0 defaults to
   > true: a private entity manager on the same connection and tables that never flushes them. Opt in now with
   > "nowo_storage_kit: { doctrine: { private_entity_manager: true } }" (false keeps the current behaviour in 1.x
   > only: 2.0 removes it; see UPGRADING.md "To 1.8.0").

   Set to `false`:

   > Setting "nowo_storage_kit.doctrine.private_entity_manager" to false is deprecated: the option is removed in 2.0,
   > which always writes the bundle entities through a private entity manager on the same connection and tables, so
   > the storage admin pages and console commands no longer flush the application's default entity manager and its
   > pending changes. Opt in now with "nowo_storage_kit: { doctrine: { private_entity_manager: true } }", or name a
   > dedicated manager with doctrine.entity_manager (see UPGRADING.md "To 1.8.0").

2. Every host-flush probe line ([Reading the host-flush probe](#reading-the-host-flush-probe)): it is your migration
   list.

   > Your pending changes to App\Entity\Order were flushed by NowoStorageKitBundle; 2.0 no longer does.

   Make the code that changes each listed class flush its own changes (see
   [Private manager only](#private-manager-only)), then set `private_entity_manager: true` and check that no line is
   left.
3. The method notices:

   > Method "Nowo\StorageKitBundle\Upload\ImageUploader::upload()" returning the
   > "Nowo\StorageKitBundle\Entity\StoredFile" entity is deprecated and removed in 2.0: use
   > "Nowo\StorageKitBundle\Upload\ImageUploaderInterface::uploadImage()", which returns a
   > "Nowo\StorageKitBundle\Upload\StoredFileView".

   > Method "Nowo\StorageKitBundle\Download\FileDelivery::respond()" is deprecated, use
   > "Nowo\StorageKitBundle\Download\FileDeliveryInterface::deliver()" and
   > "Nowo\StorageKitBundle\Download\FileDeliveryResponder" instead (removed in 2.0).

   Both are runtime notices: a call left in code that your tests do not reach fails in 2.0 with "Call to undefined
   method". Search your code (and your decorators of the `FileDelivery` / `ImageUploader` service ids) for `->respond(`
   and `->upload(` on these services.
4. With the settings-backed instance bucket, `nowo-tech/instance-settings-bundle` in your own `composer.json` (no
   runtime notice exists for it).

### The private entity manager is the only mode

| 1.8 setting | 1.8 behaviour | 2.0 |
|---|---|---|
| neither `private_entity_manager` nor `entity_manager` | admin pages and commands flush your default manager; notice | private manager on your default manager's connection, mapping and tables; your manager is never flushed |
| `private_entity_manager: true` | private manager | the same; the key has no effect (build-time deprecation, removed in 3.0): remove it |
| `private_entity_manager: false` | 1.x behaviour; notice | **build error** (`InvalidConfigurationException`): remove the key |
| `entity_manager: <the default manager's name>` | your default manager (host mode) | as the unset row: the private manager on it |
| `entity_manager: <dedicated manager>` | the dedicated manager, directly | unchanged (the DDD option) |
| dedicated `entity_manager` + `private_entity_manager: true` | the private manager on the dedicated one | unchanged |
| dedicated `entity_manager` + `private_entity_manager: false` | the dedicated manager, directly | unchanged; the key has no effect (build-time deprecation): remove it |

There is no way to keep the 1.x behaviour: an application that wants the bundle's rows in its own unit of work writes
them through the bundle's services inside its own transaction (uploads, references and the erasure share the
connection and join an open transaction as savepoints), or names a dedicated manager.

### Removed

| Removed | Deprecated since | Replacement |
|---|---|---|
| `doctrine.private_entity_manager: false` (on your default manager) | 1.8.0 | nothing to set; or a dedicated `doctrine.entity_manager` |
| The host mode: bundle flushes of your default manager, `StorageEntityManager::transactional()`'s DBAL write-unit branch | 1.8.0 | the private manager |
| `NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION`, `::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION`, `::privateEntityManagerNotice()` | 1.8.0 | none |
| `Doctrine\StorageHostFlushReporter` (`@internal`) and its service | 1.8.0 | none (no host flush left to report) |
| `Download\FileDelivery::respond()` | 1.5.0 | `FileDeliveryInterface::deliver()` + `FileDeliveryResponder::toResponse()` |
| `Upload\ImageUploader::upload()` (returned the `StoredFile` entity) | 1.3.0 (runtime notice since 1.8.0) | `ImageUploaderInterface::uploadImage()` (a `StoredFileView`; load the entity by `uuid` only if you need it) |
| `Doctrine\UploaderCollationListener::postGenerateSchemaTable()` (`@internal`, a no-op) | 1.8.1 | none (the `postGenerateSchema` collation stays) |

Kept, deprecated, removed in 3.0: the fifth argument of `StorageEntityManager::__construct()` (the 1.8 reporter, now
`?object` and ignored) and `private_entity_manager: true`.

No extension point is removed: decorators of the `FileDeliveryInterface`, `ImageUploaderInterface` and other port
services keep working. A decorator of the `FileDelivery` or `ImageUploader` service id that calls `respond()` or
`upload()` on the inner service must call `deliver()` / `uploadImage()` instead.

### Instance Settings is a suggest

`nowo-tech/instance-settings-bundle` moved from `require` to `suggest` (announced in 1.3.0), with a `conflict` on
versions below 1.3.1.

- **Settings-backed instance bucket** (`instance_profile: null`, the default): require it yourself
  (`composer require nowo-tech/instance-settings-bundle:^1.3.1`) and keep it registered. An application that only had
  it through this package loses it on `composer update` and Flex unregisters its bundle: the container build then
  fails with a message that starts with `composer require nowo-tech/instance-settings-bundle`. Nothing falls back
  silently.
- **Config-pinned instance bucket** (`profiles` or `config_profile_provider` with `instance_profile` set): it does not
  need it; you may remove it. No class of the bundle typed against Instance Settings is registered then.
- **Fresh install** (`composer require nowo-tech/storage-kit-bundle` alone): the recipe leaves `instance_profile` unset,
  so the post-install `cache:clear` stops with the same `composer require` message, by design. Require Instance
  Settings, or pin the instance bucket to a `profiles` entry, and run it again.

### Private manager only

What 1.8 listed under [Opting in: what changes](#opting-in-what-changes) is now unconditional:

- **Your pending changes are no longer flushed by the bundle.** A change of yours that was only stored because a
  StorageKit write flushed your manager is no longer stored: typically in a listener of a StorageKit event
  (`StorageAuditEvent`), of a Doctrine event of the bundle entities, an implementation of one of the bundle's ports, or
  code that changes an entity and then calls a StorageKit service or runs a StorageKit command in-process. Your code
  must commit its own change (flush your manager, or run it in your own transaction). The 1.8 probe lines are the list
  of the classes concerned.
- **The reverse, which the probe cannot see:** host code that loads a bundle row through the bundle's repository
  services (on the private manager), changes it and flushes its own manager saves nothing; the private manager drops
  the change at the next boundary with only a warning. Change bundle rows through the bundle's services, admin pages
  and commands.
- **Do not `persist()` or `flush()` bundle entities through your manager.** A bundle entity you loaded through your
  own manager (`$em->find(StoredFile::class, …)`, a route value resolver) is your manager's instance: read it, but
  change it only through the bundle's API. The admin write services load such an instance again by id in the private
  manager, so it is never taken for a new row.
- **Your associations to bundle entities hold your own manager's reference.** An entity of yours with a to-one
  association to a bundle entity (for example `Product::$image` to `StoredFile`) must hold
  `$yourManager->getReference(StoredFile::class, $id)`, never an instance of the private manager: your manager would
  take it for a new entity ("A new entity was found through the relationship") and your flush would fail. Prefer
  referencing files by `uuid` (`storage-file:<uuid>`), as documented.
- The private manager is created on the connection, configuration (mapping, metadata cache) and event manager (your
  Doctrine listeners, DoctrineEncrypt included) of your default manager; the SQL filters enabled on it are mirrored.
  The tables, the connection and the mapping do not change: no migration.
- The connection is shared: a transaction you open around an upload includes the row, and your rollback undoes it.
  DBAL 3 with `use_savepoints` off: a failed private flush inside your transaction marks your transaction
  rollback-only; turn savepoints on (always on in DBAL 4).
- The private manager is dropped at the start and end of every request, Messenger message and outermost console
  command and at `kernel.reset` (FrankenPHP worker mode with the kernel reset off included). The REQ-RUNTIME-003
  conflict answer of the bundle's routes is unchanged (409 with `Retry-After` and `X-Conflict-Code: CONFLICT_001` when
  nothing was committed, `CONFLICT_002` without `Retry-After` when part was).

### 1.x support

1.x receives security fixes for 6 months after 2.0.0 (Moderate and above backported to the last 1.x minor; Low fixed
in 2.x only, upgrade). See [SECURITY](../.github/SECURITY.md).

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

### Missing foreign keys on MySQL / MariaDB (1.5.2 to 1.8.0)

Only MySQL / MariaDB databases whose bundle tables were created or migrated with doctrine/orm 3.7.0 to 3.8.0 and
doctrine/dbal 4.5 or later are affected (PostgreSQL and SQLite are not): `storage_kit_file.profile_id` from 1.5.2,
`storage_kit_file_reference.file_id` from 1.5.3. After the update, check the schema of the bundle's entity manager:

```bash
php bin/console doctrine:schema:validate --em=<doctrine.entity_manager, or your default manager>
# or, with migrations: php bin/console doctrine:migrations:diff
```

If it reports the missing foreign keys, first deal with the rows they would reject, then add the indexes and foreign
keys. The names below are those of the default `table_prefix: ''`; with a prefix the table and generated names
differ, so take them from `php bin/console doctrine:schema:update --dump-sql` (run the statements it prints for these
two tables only, after the clean-up).

```sql
-- Files whose profile was deleted: list them (their objects stay in the bucket of the deleted profile).
SELECT f.id, f.uuid, f.profile_id, f.storage_key FROM storage_kit_file f LEFT JOIN storage_kit_profile p ON p.id = f.profile_id WHERE p.id IS NULL;
-- Either move them to an existing profile that holds their objects (replace 123 with its id; a storage_key that profile
-- already has fails the unique key uniq_storage_kit_file_key)...
UPDATE storage_kit_file f LEFT JOIN storage_kit_profile p ON p.id = f.profile_id SET f.profile_id = 123 WHERE p.id IS NULL;
-- ...or delete them (their references go too, here by hand: the foreign key below does not exist yet).
DELETE r FROM storage_kit_file_reference r JOIN storage_kit_file f ON f.id = r.file_id LEFT JOIN storage_kit_profile p ON p.id = f.profile_id WHERE p.id IS NULL;
DELETE f FROM storage_kit_file f LEFT JOIN storage_kit_profile p ON p.id = f.profile_id WHERE p.id IS NULL;

-- References of deleted files (ON DELETE CASCADE would have deleted them): check, then delete.
SELECT COUNT(*) FROM storage_kit_file_reference r LEFT JOIN storage_kit_file f ON f.id = r.file_id WHERE f.id IS NULL;
DELETE r FROM storage_kit_file_reference r LEFT JOIN storage_kit_file f ON f.id = r.file_id WHERE f.id IS NULL;

CREATE INDEX IDX_D26DA834CCFA12B8 ON storage_kit_file (profile_id);
ALTER TABLE storage_kit_file ADD CONSTRAINT FK_D26DA834CCFA12B8 FOREIGN KEY (profile_id) REFERENCES storage_kit_profile (id);
CREATE INDEX IDX_AAE94DBE93CB796C ON storage_kit_file_reference (file_id);
ALTER TABLE storage_kit_file_reference ADD CONSTRAINT FK_AAE94DBE93CB796C FOREIGN KEY (file_id) REFERENCES storage_kit_file (id) ON DELETE CASCADE;
```

`profile_id` has no ON DELETE rule (a profile with files cannot be deleted), as in the mapping. Run
`php bin/console doctrine:schema:validate` again: the mapping and the database are in sync once it passes.

## To 1.8.0

From **1.7.x** (1.8.0 requires `nowo-tech/transaction-doctrine` ^1.9.1, so update it with the bundle):

```bash
composer update nowo-tech/storage-kit-bundle nowo-tech/transaction-doctrine
```

1.8.0 is the last 1.x minor before 2.0. Without configuration it behaves as 1.7.1. It adds the
`doctrine.private_entity_manager` opt-in, a deprecation notice for each default that 2.0 changes, and a host-flush
probe that lists what the change would mean for your application. It requires `nowo-tech/transaction-doctrine` ^1.9.1.
How files are stored, delivered and authorised does not change.

### The private entity manager notice

While the bundle entities live on your default entity manager (`doctrine.entity_manager` unset, or naming your
default manager) and `doctrine.private_entity_manager` is not `true`, building the container now logs one of two
deprecations. Unset:

> Not setting "nowo_storage_kit.doctrine.private_entity_manager" is deprecated: the storage admin pages and console
> commands flush the application's default entity manager, including its own pending changes. 2.0 defaults to true: a
> private entity manager on the same connection and tables that never flushes them. Opt in now with "nowo_storage_kit:
> { doctrine: { private_entity_manager: true } }" (false keeps the current behaviour in 1.x only: 2.0 removes it; see
> UPGRADING.md "To 1.8.0").

Set to `false`:

> Setting "nowo_storage_kit.doctrine.private_entity_manager" to false is deprecated: the option is removed in 2.0,
> which always writes the bundle entities through a private entity manager on the same connection and tables, so the
> storage admin pages and console commands no longer flush the application's default entity manager and its pending
> changes. Opt in now with "nowo_storage_kit: { doctrine: { private_entity_manager: true } }", or name a dedicated
> manager with doctrine.entity_manager (see UPGRADING.md "To 1.8.0").

What it means: host-called writes (uploads, references, the GDPR erasure, the settings listener) already never flush
your manager. The admin pages and console commands do: profile save, test and switch, bucket moves (also the
Messenger handler of a move), the purge, the retired-profile cleanup and the adoption flush your default manager, and
anything your code left pending there is written by that flush too. In 2.0 the bundle writes its rows through a
private manager and never flushes your pending changes.

To opt in now (the tables, the connection and the mapping do not change; no migration, no second manager to declare):

```yaml
nowo_storage_kit:
    doctrine:
        private_entity_manager: true
```

`private_entity_manager: false` keeps the 1.x behaviour until 2.0, which removes it. A host with
`doctrine.entity_manager` naming a dedicated manager gets no notice and no probe: the bundle already never flushes
your default manager, and that option stays in 2.0.

### Reading the host-flush probe

Unless `private_entity_manager` is `true`, the bundle calls the host-flush probe of nowo-tech/transaction-doctrine right
before each flush of your default manager. Every entity class outside `Nowo\StorageKitBundle\Entity` with pending
changes in that manager (a scheduled insert or delete, a changed field, association or collection) triggers one
deprecation per request, message or command:

> Your pending changes to App\Entity\Order were flushed by NowoStorageKitBundle; 2.0 no longer does.

The bundle never changes your entities itself, so every class listed is your application's own work that relies on a
storage admin write or command to reach the database. Read the collected messages as your migration list:

1. Run your usual traffic, console commands and test suite on 1.8 with deprecations logged (the Symfony profiler, the
   `deprecation` log channel, or PHPUnit's deprecation report).
2. For each class listed, find the code that changes it without flushing: typically a listener of the bundle's
   `StorageAuditEvent`, of a Doctrine event of the bundle entities, or code that runs a bundle command in-process in the
   middle of its own changes. Make it flush its own changes (or run it in your own transaction).
3. When no message is left, set `private_entity_manager: true`. The probe is silent in that mode.

The probe is read-only: it flushes, clears, closes or resets nothing, and a probe failure never stops the write. It is
NowoTransactionDoctrineBundle's shared `nowo_transaction_doctrine.host_flush_probe` when you register that bundle,
otherwise one of the bundle's own; both are reset at every request, message and command boundary (FrankenPHP worker
mode with the kernel reset off included) and at `kernel.reset`.

### Opting in: what changes

With `private_entity_manager: true`:

- Every bundle read and write (repositories, the admin pages, the commands, uploads, references, the GDPR erasure)
  uses the private manager. It is created on the connection, configuration (mapping, metadata cache) and event manager
  (your Doctrine listeners, DoctrineEncrypt included) of `doctrine.entity_manager`, else of your default entity
  manager. The SQL filters enabled on that manager are mirrored.
- A bundle flush writes only the bundle's entities. A failed bundle write closes only the private manager, which is
  replaced on its next use; your manager stays open and is never cleared or reset.
- The private manager is dropped at the start and end of every request, Messenger message and outermost console
  command (and at `kernel.reset`): the bundle's entities never stay managed from one unit to the next. Changes the
  bundle left unflushed then are discarded and logged as a warning (classes and counts only).
- Bundle entities loaded through your own manager (`$em->find(StoredFile::class, …)`) are your manager's instances,
  not the bundle's: read them, but change them only through the bundle's API. Reference files by `uuid`, as documented.
- An entity of yours with a to-one association to a bundle entity (for example `Product::$image` to `StoredFile`) must
  hold a reference of your own manager: `$yourManager->getReference(StoredFile::class, $storedFile->getId())`. Above
  all for the `StoredFile` returned by the deprecated `ImageUploader::upload()`: it belongs to the private manager, so
  assigning it as it is makes your manager take it for a new entity ("A new entity was found through the
  relationship") and your flush fails.
- The connection is shared: a transaction you open around an upload includes the row, and your rollback undoes it (the
  private manager then forgets what it loaded or wrote in that transaction).
- The REQ-RUNTIME-003 conflict answer of the bundle's routes is unchanged: 409 with `Retry-After` and
  `X-Conflict-Code: CONFLICT_001` when nothing was committed, `CONFLICT_002` without `Retry-After` when part was.
- DBAL 3 with `use_savepoints` off: a failed private flush inside your transaction marks your transaction
  rollback-only. Turn savepoints on (always on in DBAL 4).

### The other 2.0 notices

- **`ImageUploader::upload()`**, the upload that returns the `StoredFile` entity (deprecated since 1.3), now triggers a
  runtime deprecation on every call. Call `ImageUploaderInterface::uploadImage()`, which returns a `StoredFileView`
  (`getUuid()`, URLs, sizes); load the entity by `uuid` only if you really need it.
- **`FileDelivery::respond()`** keeps its runtime deprecation of 1.5. Call `FileDeliveryInterface::deliver()` and
  `FileDeliveryResponder::toResponse()` ([To 1.5.0](#to-150), step 3).
- **`nowo-tech/instance-settings-bundle`** moves from `require` to `suggest` in 2.0 (announced in 1.3.0). Composer and
  the bundle give no runtime notice for it. If you use the settings-backed instance bucket (`instance_profile: null`,
  the default), add it to your own `composer.json` now:

  ```bash
  composer require nowo-tech/instance-settings-bundle:^1.3.1
  ```

  A config-pinned bucket (`profiles` or `config_profile_provider` with `instance_profile` set) does not need it.

### Preparing for 2.0

2.0 contains only the deprecations announced in 1.x ([Done in 2.0](#done-in-20)):

- `doctrine.private_entity_manager` defaults to `true` and `false` is removed; `doctrine.entity_manager: <name>` stays.
- `nowo-tech/instance-settings-bundle` moves to `suggest`.
- `FileDelivery::respond()` and the entity-returning `ImageUploader::upload()` are removed.

After 2.0.0 is tagged, the 1.x line gets security fixes for 6 months: Moderate and higher are backported to the last
1.x minor; Low ones are fixed in 2.x only, with the advice to upgrade.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

Patch (1.7.1), from **1.7.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

No schema change, no new dependency. Behaviour changes ([CONCURRENCY](CONCURRENCY.md)):

1. **PostgreSQL owner lock timeout.** Storage / Move writes and the first-use rows now wait at most
   `conflict_response.owner_lock_timeout` seconds (default 5; 0 = no wait) for the owner lock, then answer
   `CONFLICT_001`, instead of waiting up to your session's `lock_timeout` (no limit by default). The timeout is set
   transaction-locally for the lock statement only and your previous value is restored right after it. If you set a
   `lock_timeout` on the connection for the bundle (1.7.0, item 3), you can remove it, or keep it for your own
   statements.
2. **First use inside your transaction on MySQL / MariaDB.** The re-read of the instance's `storage` row (or of a
   config profile's row) under the owner lock is a locking read (`FOR UPDATE` on the instance's few active rows,
   index `scope, state`). Inside a transaction you opened, those rows stay locked until your commit, so another
   request's first use waits for it (at most `conflict_response.owner_lock_timeout`: the session's
   `innodb_lock_wait_timeout` is lowered for that statement only and restored after it) instead of creating a
   duplicate. A first use made inside your own transaction that loses this race always throws a retry-safe
   `TransientStorageFailure` (a `RetryableException`): its snapshot (REPEATABLE READ) was opened before the lock and
   cannot see the other request's row. Roll back and replay, as for a deadlock. Run first uploads outside long
   transactions where you can. Rows created before 1.7.1:
   [Duplicate instance rows](#duplicate-instance-rows-140161).
3. **Commit tracking for `CONFLICT_002`.** Without transaction-doctrine's bundle (or with its `commit_detection`
   off), the bundle registers transaction-doctrine's commit-tracking DBAL middleware on the connection of
   `doctrine.entity_manager` (your default connection when it is `null`) and a `kernel.request` listener that takes
   the bundle's commit mark. A conflict on a bundle route after something committed earlier in the request then
   answers `CONFLICT_002` without `Retry-After`. Your other connections are never wrapped. To keep the 1.7.0
   behaviour (no middleware), set `nowo_storage_kit.conflict_response.commit_tracking: off`. With transaction-doctrine's
   `commit_detection` on, nothing is added.

   **DBAL 3 note.** With `commit_tracking: bundle` (the default) the bundle's connection (your default connection when
   `doctrine.entity_manager` is `null`) is wrapped by transaction-doctrine's commit-tracking driver connection, whose
   `commit()` returns nothing. On DBAL 3, `Doctrine\DBAL\Connection::commit()` therefore returns `null` instead of
   `true` on that connection (DBAL 4's is `void` anyway). The ORM is not affected (it only checks `=== false`). If your
   own code tests the return value of `commit()`, set `commit_tracking: off`, or register transaction-doctrine's bundle
   with `commit_detection`.

## To 1.7.0

From **1.6.1**:

```bash
composer update nowo-tech/storage-kit-bundle
```

Next minor (1.7.0), from **1.6.x**:

```bash
composer update nowo-tech/storage-kit-bundle
```

No schema change. New dependencies `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6.1
(installed with the bundle; you do not have to register transaction-doctrine's own bundle). Behaviour changes
(REQ-RUNTIME-003, [CONCURRENCY](CONCURRENCY.md)):

1. **Lock conflicts answer 409 instead of 500.** A deadlock, lock wait timeout or serialization failure on the Storage /
   Move pages, the upload endpoint or the download endpoint answers **409** with `X-Conflict-Code: CONFLICT_001` and
   `Retry-After` (nothing committed: retry), or `CONFLICT_002` without `Retry-After` (part of the request committed:
   reload). Pages go through your error page (an `HttpException`); the upload JSON keeps `error` / `message` and adds
   `code` (`error` is `conflict`). To keep a 503, set `nowo_storage_kit.conflict_response.status: 503`. Unique
   violations and lost connections are not mapped and stay errors. Your own routes are never answered by the bundle.
2. **Uploads rethrow lock conflicts.** `ImageUploaderInterface::uploadImage()` / `SquareImageUploaderInterface`
   (and the deprecated `ImageUploader::upload()`) used to turn every insert failure into `UploadRefused` (`storage`,
   503). A deadlock or lock wait timeout of the insert is now rethrown as the DBAL exception (a `RetryableException`),
   after the objects written are deleted again, so your transaction can roll back and replay. Catch it (or let
   transaction-doctrine / your retry layer handle it) if you call the uploader from your own code.
3. **Owner lock on PostgreSQL.** Storage / Move writes take `pg_advisory_xact_lock()` and wait up to the session's
   `lock_timeout`. With the PostgreSQL default (0: no limit) a stuck writer makes the next one wait; set a
   `lock_timeout` on the connection (for example 5s). On MySQL / MariaDB the wait is `conflict_response.owner_lock_timeout`
   (default 5 seconds, `GET_LOCK`).
4. **`StorageMove` / `StorageSyncer` return types (public services, see
   [CONFIGURATION](CONFIGURATION.md#container-parameters-and-services)).** BC note for code that calls them directly:
   - `StorageMove::saveTarget()`, `cancel()`, `discard()` and `StorageSyncer::cancel()`: `void` → `bool`;
   - `StorageMove::start()`: `StorageSync` → `?StorageSync`; callers must handle `null`;
   - `false` / `null` mean **nothing was written**: the owner changed meanwhile (another target saved, the target
     switched to or discarded, a sync already open, or a sync that completed or failed before the cancel). The pages
     show "This action is not available now.";
   - `saveTarget()` takes an optional fourth argument, a callable that applies the form to the target inside the unit.
5. **Default entity manager.** With `doctrine.entity_manager: null` the Storage / Move units still flush your default
   manager (as before; REQ-ARCH-001 rule 6 changes in 2.0) but inside one transaction-doctrine unit; a failed flush no
   longer leaves a closed manager behind (it is replaced) and an open one is never cleared or reset. The switch and
   `nowo:storage-kit:adopt-instance-bucket` no longer use `wrapInTransaction()`, which closed it on any failure. The
   bundle does not use transaction-doctrine's `forHostEntityManager()` (it would throw `LogicException` on the
   decrypted bucket secret, which always looks like a pending change); with a dedicated manager the units use
   `forEntityManager()`.

### Duplicate instance rows (1.4.0–1.6.1)

From 1.4.0 to 1.6.1 two first uploads at the same time (or the first selection of a provider in the `storage`
section racing an upload) could create two active instance rows that read the `storage` section; config profiles
could get two rows the same way. 1.7 creates these rows once, under a lock, but does not repair existing duplicates.
On MySQL / MariaDB 1.7.0 could still create one when the first use ran inside a transaction you opened (fixed in
1.7.1, [To 1.7.1](#to-171)). Check once after upgrading (`<prefix>` is `doctrine.table_prefix`, empty by default):

```sql
-- More than one row: the instance has duplicate active rows
SELECT id, source FROM <prefix>storage_kit_profile
 WHERE scope IS NULL AND config_name IS NULL AND state = 'active' ORDER BY id;

-- Config profiles with more than one row
SELECT config_name, COUNT(*) FROM <prefix>storage_kit_profile
 WHERE config_name IS NOT NULL GROUP BY config_name HAVING COUNT(*) > 1;

-- Files per row
SELECT profile_id, COUNT(*) FROM <prefix>storage_kit_file GROUP BY profile_id;
```

**No switch since the duplicate** (no `retired` instance row newer than the extra one): both rows read the same
section, so re-pointing is safe. Keep the oldest active row (`<keep>`, lowest id) and, for each extra row `<extra>`:

```sql
UPDATE <prefix>storage_kit_file SET profile_id = <keep> WHERE profile_id = <extra>;
DELETE FROM <prefix>storage_kit_profile WHERE id = <extra>;
```

**A switch happened since the duplicate:** the switch moved only the oldest row's files; the extra row still reads
the section, which now names the NEW bucket, but its files are still in the OLD bucket (they do not download). The
old connection is kept on the `retired` instance row the switch created (`source = 'database'`, `state = 'retired'`,
the one whose bucket is the old one). Re-point the extra row's files to that retired row `<retired>`, delete the extra
row, then run a new move (Move page or `nowo:storage-kit:sync`) to copy them to the new bucket and switch again:

```sql
UPDATE <prefix>storage_kit_file SET profile_id = <retired> WHERE profile_id = <extra>;
DELETE FROM <prefix>storage_kit_profile WHERE id = <extra>;
```

Do not forget previous buckets (`nowo:storage-kit:forget-retired`) before that move has completed.

**Config-profile duplicates** are harmless: every row of a `config_name` resolves to the same `profiles` entry.
Clean-up only: re-point the files of the extra rows to the oldest row of that name and delete the extra rows, as above.

## To 1.6.1

From **1.6.0**:

```bash
composer update nowo-tech/storage-kit-bundle
```

Hosts on PostgreSQL with DBAL 3 that hit the `NOT NULL` violation on `storage_kit_file_reference.id` (1.0.0–1.6.0) are
fixed by this release without a migration: no schema or configuration change.

## To 1.6.0

Target **1.6.0**, from **1.5.x**: `composer update nowo-tech/storage-kit-bundle`. New features only, no breaking change,
no schema change, no configuration change required.

- Square images (profile pictures, logos): inject `Upload\SquareImageUploaderInterface` and call
  `uploadSquareImage($user, $contextType, $contextId, $bytes, $name)`. Tune `upload.square.max_bytes` (default 2 MiB),
  `upload.square.max_pixels` (default 16 MP) and `upload.square.size` (default 256 px) if needed; keep PHP
  `upload_max_filesize` / `post_max_size` above `max_bytes`. **PHP GD must be built with WebP** (`--with-webp`;
  `gd_info()['WebP Support']`): without it every square upload is refused with `off` and
  `SquareImageProcessor::supported()` returns false. UserKitBundle 1.18 (`owner_images`) uses it for avatars and
  organization logos.
- Removing a file at once (a replaced picture): inject `Lifecycle\FileRemoverInterface`. Call `remove()` after your
  transaction committed (it calls the bucket), `expire()` inside one; inside a transaction, pass the returned
  `RemovedFile` to `afterCommit()` once you committed so the audit event is recorded.
- Listeners of `StorageAuditEvent` may see the new actions `file_removed` and `file_expired` (details: file, context).
- An expired file (removed, or handed to the purge by a personal-data erasure) is no longer downloadable: its URL
  answers 404 at once instead of until the next purge.
- `ImageUploader` has a new last, optional constructor argument (`SquareImageProcessor`); positional calls keep working.

## To 1.5.3

Target **1.5.3**, from **1.5.2** (or any 1.x): `composer update nowo-tech/storage-kit-bundle`. Security fix for
look-alike context ids, source ids, owners and scopes on MySQL / MariaDB (REQ-SEC-006, see the CHANGELOG): every lookup,
count, purge step and reference write now matches `context_id`, `owner`, `source_id` and `scope` byte for byte. No
configuration change. PostgreSQL hosts: nothing to do (deterministic collations already compare exactly). SQLite hosts:
nothing to do unless you declared those columns `COLLATE NOCASE` / `RTRIM` yourself (the bundle now compares with
`COLLATE BINARY` anyway).

1. **Find the look-alike families, before the `ALTER`.** Each query groups by the column's **own** collation on purpose:
   that is the comparison the pre-1.5.3 code used, so each group is exactly a set of ids it treated as one. The hex in
   `spellings` shows trailing spaces and invisible characters, and keeps `DISTINCT` from folding them. Add your
   `doctrine.table_prefix` to the table names. If the `ALTER` already ran, write the old collation after the `GROUP BY`
   column (for example `GROUP BY context_type, context_id COLLATE utf8mb4_unicode_ci`, MariaDB
   `COLLATE utf8mb4_uca1400_ai_ci`).

   ```sql
   -- Contexts: files of one family were handled as one context by the purge (step 2) and by reference writes.
   SELECT context_type, MIN(context_id) AS family,
          GROUP_CONCAT(DISTINCT CONCAT('[', context_id, '] ', HEX(context_id)) SEPARATOR ', ') AS spellings,
          COUNT(DISTINCT CAST(context_id AS BINARY)) AS ids, COUNT(*) AS files
   FROM storage_kit_file
   GROUP BY context_type, context_id
   HAVING ids > 1;

   -- Texts: references of one family were read, dropped and forgotten together.
   SELECT source_type, MIN(source_id) AS family,
          GROUP_CONCAT(DISTINCT CONCAT('[', source_id, '] ', HEX(source_id)) SEPARATOR ', ') AS spellings,
          COUNT(DISTINCT CAST(source_id AS BINARY)) AS ids, COUNT(*) AS references_held
   FROM storage_kit_file_reference
   GROUP BY source_type, source_id
   HAVING ids > 1;

   -- Usage owners: StoredFileRepository::bytesFor() summed the family together (upload limits).
   SELECT MIN(owner) AS family,
          GROUP_CONCAT(DISTINCT CONCAT('[', owner, '] ', HEX(owner)) SEPARATOR ', ') AS spellings,
          COUNT(DISTINCT CAST(owner AS BINARY)) AS owners, SUM(total_bytes) AS bytes
   FROM storage_kit_file
   WHERE owner IS NOT NULL
   GROUP BY owner
   HAVING owners > 1;

   -- Scopes: the Storage / Move pages and the uploads of one member could use another member's profile.
   SELECT MIN(scope) AS family,
          GROUP_CONCAT(DISTINCT CONCAT('[', scope, '] ', HEX(scope), ' ', state, ' ', uuid) SEPARATOR ', ') AS profiles,
          COUNT(DISTINCT CAST(scope AS BINARY)) AS scopes
   FROM storage_kit_profile
   WHERE scope IS NOT NULL
   GROUP BY scope
   HAVING scopes > 1;
   ```

   What a family means:

   - **Contexts.** If one member of a family was deleted, the purge may have dropped the references of the other
     members' files and, after `purge.retention_days`, deleted them (`files_purged` audit events give the runs). That
     cannot be undone from the bundle. A text of one member could also reference (and so keep from the purge) a file
     of another member; the next save of that text corrects it.
   - **Texts.** Saving one member of a family could drop the references of the others, and a purge that found one
     member gone forgot the references of all of them: their files may have been orphaned and purged. Save the
     surviving texts again to rebuild their references (an image already purged stays missing).
   - **Owners.** Nothing to repair: usage is exact from 1.5.3 on.
   - **Scopes.** Every member of a family used the profile of whichever member had one: its uploads went to that
     bucket, and a manager of one member could open, edit, test, move and switch the other member's bucket from its
     Storage and Move pages. Check the `profile_saved`, `move_*` audit events of each member. Files keep their
     profile, so after the upgrade they still download from the bucket they were written to; a member with no profile
     of its own uploads to the instance bucket (or not at all) until its managers set one up. To move a member's files
     to its own bucket, set its bucket up and run *Move to another bucket* from the member that owns the old profile.

2. **Schema (MySQL / MariaDB).** The bundle now generates a binary NO PAD collation for these four columns too, so
   `doctrine:migrations:diff` (or `doctrine:schema:update --dump-sql`) produces this change. Run it, or write the
   migration yourself:

   ```sql
   -- MySQL 8.0.17+ / 8.4
   ALTER TABLE storage_kit_file
     MODIFY context_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
     MODIFY owner VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
   ALTER TABLE storage_kit_file_reference
     MODIFY source_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
   ALTER TABLE storage_kit_profile
     MODIFY scope VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
   -- MariaDB: COLLATE utf8mb4_nopad_bin; MySQL 5.7 or older than 8.0.17: COLLATE utf8mb4_bin (PAD SPACE)
   ```

   **The `storage_kit_file_reference` statement is required for safety**, not only for speed: its unique key
   `uniq_storage_kit_file_reference (file_id, source_type, source_id)` folds with the column, so while it does, two
   look-alike texts cannot both reference one file. The bundle then skips the second reference rather than fail
   (a warning "file reference(s) … skipped … run the storage_kit_file_reference ALTER" is logged; never retried with
   the look-alike's row), but that file is orphaned as soon as the first text drops it.
   The statements rewrite the tables and their indexes; going from a folding collation to a binary one cannot create
   duplicates, so none of them can fail on the unique key. On large tables run them in a maintenance window or with an
   online schema tool. Until they run, the exact SQL filters and the `===` re-checks keep every lookup exact.

   The bundle picks `utf8mb4_0900_bin` only when it knows the server is MySQL 8.0.17 or later: with a `server_version`
   without the patch number (`8`, `8.0`) DBAL selects its MySQL 5.7 platform, the bundle falls back to `utf8mb4_bin`
   and logs a warning when the schema is generated. Set the full `server_version` (for example `8.0.36`, `8.4.3`).

3. **Refused values.** These ids are now refused where they enter, never trimmed or cut:
   - an owner key (`FileContextTarget::$owner`) that is empty, longer than 64 characters, invalid UTF-8, padded with
     white space (NBSP included) or holding a control, zero-width, BOM or bidi character: the constructor throws, as
     it already did for an empty or too long key;
   - a reference source id (`FileReferenceSourceInterface::id()`, `FileReferencesInterface::sync()`) with the same
     defects: the text references nothing (logged), so its files become orphans; give such texts a plain id;
   - a file context returned by `FileReferenceSourceInterface::context()` outside the context syntax: the text
     references no new file (logged);
   - a scope key returned by your `scopes.resolver` outside the scope syntax: uploads of that target are off.

4. **Host extension points.** `FileContextInterface::readable()` / `existing()`, `FileReferenceSourceInterface::existing()`
   and `ExistingIds::of()` must return ids exactly as asked: the bundle compares the answer with `===`, so an id your
   lookup returns in another spelling (a folding host column answers `abc` for `ABC`) counts as not readable / gone.
   `ExistingIds::of()` already filters its answer that way.

## To 1.5.2

Target **1.5.2**, from **1.5.1** (or any 1.x): `composer update nowo-tech/storage-kit-bundle`. Security fix for
look-alike uploader identifiers on MySQL / MariaDB (see the CHANGELOG): the GDPR export / erasure and the backfill now
match `uploaded_by` / `uploaded_by_key` byte for byte. No configuration change, no code change. PostgreSQL and SQLite
hosts: nothing to do (the new collation listener does not touch them).

1. **Schema (MySQL / MariaDB).** The bundle now generates a binary NO PAD collation for `storage_kit_file.uploaded_by`
   and `uploaded_by_key`, so `doctrine:migrations:diff` (or `doctrine:schema:update`) produces this change. Run it, or
   write the migration yourself (add your `doctrine.table_prefix` to the table name):

   ```sql
   -- MySQL 8.0 / 8.4
   ALTER TABLE storage_kit_file
     MODIFY uploaded_by VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
     MODIFY uploaded_by_key VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
   -- MariaDB: COLLATE utf8mb4_nopad_bin; MySQL 5.7: COLLATE utf8mb4_bin
   ```

   The statement rewrites the table and its `idx_storage_kit_file_uploader*` indexes (no unique key is involved, so it
   cannot fail on duplicates); on a large table run it in a maintenance window or with an online schema tool. Until it
   runs, the PHP re-check alone keeps the export and the erasure exact (the SQL pre-filter just reads look-alike rows
   and drops them).
2. **Existing look-alike rows.** The `ALTER` changes no data. Rows that collide only under the old collation become
   distinct, which is what you want: each person's export and erasure now finds only their own files. Find the families
   that were affected, **before the `ALTER`**:

   ```sql
   SELECT MIN(uploaded_by) AS family,
          GROUP_CONCAT(DISTINCT CONCAT('[', uploaded_by, '] ', HEX(uploaded_by)) SEPARATOR ', ') AS spellings,
          COUNT(DISTINCT CAST(uploaded_by AS BINARY)) AS identifiers,
          COUNT(DISTINCT CAST(uploaded_by_key AS BINARY)) AS keys_used
   FROM storage_kit_file
   WHERE uploaded_by IS NOT NULL
   GROUP BY uploaded_by
   HAVING identifiers > 1;
   ```

   It groups by the column's own collation on purpose: that is the comparison the pre-1.5.2 lookups and backfill used,
   so each group is exactly a set of identifiers they treated as one person, and it surfaces the rows the backfill may
   have keyed with another member's key. A fixed collation would not: `utf8mb4_0900_ai_ci` is NO PAD and misses the
   `bob` / `bob ` families of a PAD SPACE column (MariaDB's default `utf8mb4_uca1400_ai_ci`, `utf8mb4_unicode_ci`,
   `utf8mb4_general_ci`). The hex in `spellings` shows trailing spaces and keeps `DISTINCT` from folding them. If the
   `ALTER` already ran, write the old collation after `GROUP BY uploaded_by` (for example
   `GROUP BY uploaded_by COLLATE utf8mb4_unicode_ci`, MariaDB `COLLATE utf8mb4_uca1400_ai_ci`).

   - **If you ran `nowo:storage-kit:backfill-uploader-keys` before 1.5.2**, a family may hold rows of one identifier
     stamped with another identifier's key (for example `Alice`'s files with `alice`'s key): the keyed export and
     erasure of `alice` would keep including them. Check the families above: for each identifier whose rows carry a
     key that is not its own user's key, reset those rows and run the backfill again (it now keys each exact
     identifier with its own user, and skips a user the provider loads under another identifier):

     ```sql
     UPDATE storage_kit_file SET uploaded_by_key = NULL
     WHERE CAST(uploaded_by AS BINARY) = CAST('Alice' AS BINARY) AND uploaded_by_key = 'App\\Entity\\User:7';
     ```
   - **If an erasure ran for a member of a family before 1.5.2**, the other members' files may already be anonymised
     (`original_name` set to the anonymous name, uploader cleared) and, when no text showed them, purged. That cannot
     be undone from the bundle; the `files_erased` audit events tell you when erasures ran.
3. **Uploads with an unusual identifier.** An uploader identifier (or key) with leading / trailing white space, a
   control, zero-width or bidi character, invalid UTF-8 or more than 180 characters is no longer stored (the column
   stays `NULL`); before, a long identifier was cut to 180 characters. Such files are found by the uploader key when
   there is one, otherwise not at all by the GDPR export / erasure: give such users a usable `getId()` (or your own
   `personal_data.uploader_key_resolver`).

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
   Existing installs keep `null` (the default manager) unchanged — it stays the default in 1.x; 2.0 writes through a
   private manager on your default connection instead (amended in 1.8.0, [Done in 2.0](#done-in-20)); switching later needs no migration (same tables), but
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
   decides case sensitivity: on MySQL / MariaDB a `*_ci` (or PAD SPACE) comparison stamps `Alice`'s files with
   `alice`'s key, so compare `CAST(u.email AS BINARY) = CAST(f.uploaded_by AS BINARY)` (see [To 1.5.2](#to-152)).
   Identifiers longer than 180 characters were cut at upload and never match.

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
