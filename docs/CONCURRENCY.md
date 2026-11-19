# Concurrency (REQ-RUNTIME-003)

How the bundle's writes stay correct when the same data is written at the same time: two admins, two Messenger
workers, a retried request. Since 1.7 every write path has a transaction, a concurrency control and a defined answer to
a lock conflict. Worker-mode state (what survives between requests) is covered separately in
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Table of contents

- [Write paths](#write-paths)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Host options](#host-options)
- [Entity managers](#entity-managers)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

"Unit" is `StorageEntityManager::transactional()` (transaction-doctrine runner, one transaction, the manager flushed
before the commit) unless noted. "Owner lock" serializes the writes of one storage owner (the instance or one scope):
MySQL / MariaDB `GET_LOCK()`, PostgreSQL `pg_advisory_xact_lock()`, none on SQLite (one writer).

| Path | Transaction | Control (rule 4) | Conflict answer |
| --- | --- | --- | --- |
| Storage page: save the bucket (`StorageSettingsController`) | Unit | Owner lock; the owner's active profile is read again inside the unit (a first save racing another, or a profile switched away meanwhile, writes nothing and shows "Someone else changed this bucket meanwhile") | 409 page (HttpException) |
| Storage / Move page: *Test connection* (`ProfileTester`) | One DBAL `UPDATE` of the test fields (no flush) | Last test wins (a probe result, not user data) | 409 page |
| Move page: save the new bucket (`StorageMove::saveTarget`) | Unit | Owner lock; the owner's target read again inside the unit (at most one target) | 409 page |
| Move page: start / resume copying (`StorageMove::start`) | Unit; the Messenger message is dispatched after the commit | Owner lock; both profiles and the pair's sync read again inside the unit (never two open syncs, never dispatched twice) | 409 page |
| Move page: stop copying (`StorageMove::cancel`) | One guarded `UPDATE` | Compare-and-set on `status` (only from queued / running: a runner that completed or failed meanwhile is not overwritten) | 409 page |
| Move page: switch (`StorageMove::switch`) | Unit (the `storage` Instance Settings write, the file re-pointing `UPDATE` and both profiles) | Owner lock; the sync, the files uploaded since it and both profiles read again inside the unit | 409 page |
| Move page: forget the new bucket / previous buckets | Unit | Owner lock (forget all from the console: no lock); target discard refused while a sync to it is open | 409 page |
| Copy runner (`StorageSyncer::run`, Messenger handler, `nowo:storage-kit:sync`) | One small transaction per file (progress), no transaction around the bucket I/O | Symfony Lock per sync plus an atomic claim (`UPDATE … WHERE status = queued OR stale`); status changes are compare-and-set from `running`, so a cancel committed meanwhile wins | Console / worker: the failure is rethrown (Messenger retries) |
| `nowo:storage-kit:adopt-instance-bucket` | Unit | Owner lock (instance) | Console: rethrown |
| Upload endpoint / `ImageUploaderInterface` | One DBAL `INSERT` (a savepoint inside your transaction) | New random UUID and key per upload (`uniq_storage_kit_file_key`) | 409 JSON `{error: conflict, message, code}`; the objects already written are deleted again |
| File references (`FileReferencesInterface::sync` / `forget`, the reference listener) | Your transaction (it runs after your flush); the insert in its own savepoint | Unique key `(file_id, source_type, source_id)` + `INSERT … SELECT … NOT EXISTS`; a unique violation (a concurrent save of the same text) is caught and dropped, never mapped to a conflict | Your route: the lock wait is rethrown to your code (the bundle never answers host routes) |
| Download endpoint | No write | — | 409 `text/plain` if a read meets a lock |
| Purge (`nowo:storage-kit:purge`) | Keyset batches, one statement or flush per batch | Each batch re-checks that no reference appeared before deleting a row; `orphaned_since` set only when still unreferenced | Console: rethrown |
| GDPR erasure (`StoredFilesPersonalDataInterface`), Instance Settings listener, uploader-key backfill | One DBAL statement per chunk (`updateRows()`: one savepoint / transaction for all chunks) | Idempotent overwrite to fixed values | Your route / console: rethrown |

`grep -rn "flush()" src/` hits are all inside a unit (`StorageMove::swap()`, `StorageSyncer::open()`, the units of
the table above), the runner's per-file progress (`StorageSyncer::runLocked()`) or the purge batches, by design.

## Conflict answer and codes

A lock conflict, deadlock, lock wait timeout or serialization failure (MySQL / MariaDB 1213, 1205, 1020; PostgreSQL
40001, 40P01, 55P03), or the owner lock not obtained in time, on one of the bundle's own routes answers:

| Code | When | Status | Headers |
| --- | --- | --- | --- |
| `CONFLICT_001` | Nothing was committed: retrying is safe | 409 (or 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request was committed (transaction-doctrine's request commit mark, a Messenger handler that already completed, a lost savepoint): read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

- The Storage and Move pages become an `HttpException` with that status and those headers, rendered by your error page.
- The upload endpoint keeps its JSON error shape and adds the code: `{"error": "conflict", "message": "…", "code": "CONFLICT_001"}`.
- The download endpoint answers `text/plain`.
- Not conflicts, never mapped: unique (and other constraint) violations and lost connections. They stay real errors.
- Host routes are never answered: a lock wait inside the reference listener or an upload called from your code is
  rethrown to you (rule 8: never swallowed).
- "Part committed" needs transaction-doctrine's commit detection (its bundle, `commit_detection: true`) to see commits
  made earlier in the same request by your code or another bundle; without it the failure alone decides.

The listener is `StorageKitConflictListener` (`kernel.exception`, priority -64: after your own exception listeners,
before the framework's error listener).

## Host options

```yaml
nowo_storage_kit:
    conflict_response:
        status: 409               # or 503 for hosts that keep a Service Unavailable answer
        retry_after: 1            # seconds, CONFLICT_001 only
        owner_lock_timeout: 5     # seconds a Storage / Move write waits for another one of the same owner (MySQL / MariaDB)
```

On PostgreSQL the owner lock waits up to the session's `lock_timeout` (set it, for example `SET lock_timeout = '5s'` in
your connection's `options` or a DBAL middleware; the default 0 waits without limit). Cross-origin API clients that
read the code must list `X-Conflict-Code` in `Access-Control-Expose-Headers` (the bundle's routes are same-origin).

## Entity managers

- **Dedicated manager** (`doctrine.entity_manager: storage_kit`, recommended): units use transaction-doctrine's
  `forEntityManager()`, which resets that manager after a storage failure. Your manager is never touched.
- **Default manager** (`entity_manager: null`, the 1.x default): units use the DBAL runner (`forConnection()`) and
  flush the manager themselves, as these admin pages did before 1.7 (REQ-ARCH-001 rule 6, changed in 2.0 only). An
  open manager is never cleared or reset; one a failed flush closed is replaced through the registry.
  `forHostEntityManager()` is not used: DoctrineEncrypt decrypts the bucket secret in place after loading, so every
  loaded profile looks changed to its pending-changes check.
- Worker mode with kernel reset false: nothing of a unit is kept on a service; the dedicated manager is cleared at every
  request / message / command boundary ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Tests

- `tests/Integration/Concurrency/ConcurrencyRaceTest.php`: two kernels (two connections) on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16/17 (CI job "Lifecycle on …", required): two first saves of a bucket, a cancel racing the copy runner,
  a real lock wait on the switch (409 + `CONFLICT_001` + `Retry-After`, nothing changed, retry succeeds), two saves of
  the same text reference (one row; the same failure after an earlier commit answers `CONFLICT_002`). Lock waits are
  capped at one second.
- `tests/Integration/Functional/ConcurrentWritesTest.php`: the same re-checks on one connection (SQLite and the three
  databases) with a statement hook as the other writer, plus a deadlock on the settings page and on the upload insert.
- `tests/Unit/Concurrency`, `tests/Unit/EventListener/StorageKitConflictListenerTest.php`,
  `tests/Unit/Doctrine/OwnerLockTest.php`: the mapping (001 / 002, unique violations and lost connections excluded) and
  the lock per platform.

## Residual risks

- **Stale form, last writer wins.** Two admins editing the same existing bucket both save; the second save overwrites
  the first (both are audited with the changed field names). The owner lock prevents duplicates, not this.
- **Owner lock inside your transaction.** If you call a Move / Storage write inside a transaction you opened, the
  MySQL / MariaDB lock is released when the bundle's unit ends, before your commit.
- **PostgreSQL REPEATABLE READ.** With a REPEATABLE READ default, the re-check inside a unit reads the snapshot taken at
  its first statement (the lock), so a change committed while waiting for the lock is not seen. READ COMMITTED (the
  default) is not affected.
- **Uploads while switching.** A file uploaded between the switch's check and its commit stays on the retired bucket
  (still downloadable, see *Switch* in [USAGE.md](USAGE.md)).
- **Purge versus a re-reference.** An orphan past its retention that a text references again while the purge deletes
  its objects is still deleted (the reference row goes with it). The window is the time of the bucket deletes of that
  batch, after at least `purge.retention_days` without any reference.
- **Orphan mark on PostgreSQL.** A text dropping a file while another adds it can leave `orphaned_since` set on a
  referenced file; the purge re-checks references before deleting, so the file is kept.
- **Upload objects.** A failed upload insert deletes its objects again; if that delete fails too, the objects stay in
  the bucket with no row (logged).
