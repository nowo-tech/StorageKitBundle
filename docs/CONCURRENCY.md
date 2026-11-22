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
MySQL / MariaDB `GET_LOCK()`, PostgreSQL `pg_advisory_xact_lock()` (both wait at most
`conflict_response.owner_lock_timeout`, PostgreSQL since 1.7.1), none on SQLite (one writer).

| Path | Transaction | Control (rule 4) | Conflict answer |
| --- | --- | --- | --- |
| Storage page: save the bucket (`StorageSettingsController`) | Unit | Owner lock; the owner's active profile is read again inside the unit (a first save racing another, or a profile switched away meanwhile, writes nothing and shows "Someone else changed this bucket meanwhile"); 2.1: row version of the page, compare-and-set inside the unit (`UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?`, which also holds the row lock until the commit), so a page opened before another save writes nothing | Lock conflict: 409 page (HttpException). Stale page: 409 form, `X-Error-Code: STORAGE_KIT_STALE_EDIT` |
| Storage / Move page: *Test connection* (`ProfileTester`) | One DBAL `UPDATE` of the test fields (no flush) | Last test wins (a probe result, not user data) | 409 page |
| Move page: save the new bucket (`StorageMove::saveTarget`) | Unit | Owner lock; the owner's target read again inside the unit (at most one target); 2.1: row version of the page, compare-and-set inside the unit (your code without a version: advanced unchecked, so a page opened before is stale) | Lock conflict: 409 page. Stale page: 409 form, `X-Error-Code: STORAGE_KIT_STALE_EDIT`; your code: `StaleEditException` |
| Move page: start / resume copying (`StorageMove::start`) | Unit; the Messenger message is dispatched after the commit | Owner lock; both profiles and the pair's sync read again inside the unit (never two open syncs, never dispatched twice) | 409 page |
| Move page: stop copying (`StorageMove::cancel`) | One guarded `UPDATE` | Compare-and-set on `status` (only from queued / running: a runner that completed or failed meanwhile is not overwritten) | 409 page |
| Move page: switch (`StorageMove::switch`) | Unit (the `storage` Instance Settings write, the file re-pointing `UPDATE` and both profiles) | Owner lock; the sync, the files uploaded since it and both profiles read again inside the unit | 409 page |
| Move page: forget the new bucket / previous buckets | Unit | Owner lock (forget all from the console: no lock); target discard refused while a sync to it is open | 409 page |
| Copy runner (`StorageSyncer::run`, Messenger handler, `nowo:storage-kit:sync`) | One small transaction per file (progress), no transaction around the bucket I/O | Symfony Lock per sync plus an atomic claim (`UPDATE … WHERE status = queued OR stale`); status changes are compare-and-set from `running`, so a cancel committed meanwhile wins | Console / worker: the failure is rethrown (Messenger retries) |
| `nowo:storage-kit:adopt-instance-bucket` | Unit | Owner lock (instance) | Console: rethrown |
| Upload endpoint / `ImageUploaderInterface` | One DBAL `INSERT` (a savepoint inside your transaction) | New random UUID and key per upload (`uniq_storage_kit_file_key`) | 409 JSON `{error: conflict, message, code}`; the objects already written are deleted again |
| First use: the instance's `storage` row, a config profile's row (`StorageProfiles`, from an upload or the settings listener) | `StorageEntityManager::exclusively()`: a savepoint inside your transaction, else one of its own; no manager flushed | Owner lock, then a locking re-read (1.7.1: `FOR UPDATE` on MySQL / MariaDB, waiting at most `owner_lock_timeout`, so a row inserted by another open transaction is waited for); a row the locking read sees but your snapshot does not is a retry-safe conflict, never a duplicate (inside your own transaction on MySQL / MariaDB the losing first use therefore always fails retry-safe and never reuses the row) | Bundle routes: 409 `CONFLICT_001`; your code: rethrown (`TransientStorageFailure` or the lock wait timeout) |
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
| `CONFLICT_002` | Part of the request was committed (transaction-doctrine's request commit mark, or the bundle's own mark on its connection since 1.7.1; a Messenger handler that already completed; a lost savepoint): read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

- The Storage and Move pages become an `HttpException` with that status and those headers, rendered by your error page.
- **A stale bucket form is not a lock conflict** (2.1, rule 4): someone saved the bucket after the page was opened.
  Nothing is written, repeating the request can never succeed, so it is a domain answer: 409, the form rendered again
  with "Someone else saved this bucket after you opened the page…", `X-Error-Code: STORAGE_KIT_STALE_EDIT`, no
  `X-Conflict-Code` and no `Retry-After`. The admin reloads the page. A missing or malformed `rowVersion` counts as
  stale. The version is read from the database for each page (never from the identity map) and is never written by
  the ORM (`insertable: false, updatable: false`), so it cannot go backwards.
- The upload endpoint keeps its JSON error shape and adds the code: `{"error": "conflict", "message": "…", "code": "CONFLICT_001"}`.
- The download endpoint answers `text/plain`.
- Not conflicts, never mapped: unique (and other constraint) violations and lost connections. They stay real errors.
- Host routes are never answered: a lock wait inside the reference listener or an upload called from your code is
  rethrown to you (rule 8: never swallowed).
- "Part committed" (1.7.1, audit UH-SK-4): with transaction-doctrine's bundle and `commit_detection: true`, its
  request mark sees every commit made earlier in the same request (your code, another bundle). Without it the bundle
  counts the commits of its own connection (`conflict_response.commit_tracking: bundle`, the default): transaction-
  doctrine's commit-tracking DBAL middleware wraps the connection of `doctrine.entity_manager` only, and
  `StorageKitConflictListener::onRequest()` takes the bundle's mark at `kernel.request` (priority 4096). A commit on
  another connection is then not seen. `commit_tracking: off`: the failure alone decides.

The listener is `StorageKitConflictListener` (`kernel.exception`, priority -64: after your own exception listeners,
before the framework's error listener).

## Host options

```yaml
nowo_storage_kit:
    conflict_response:
        status: 409               # or 503 for hosts that keep a Service Unavailable answer
        retry_after: 1            # seconds, CONFLICT_001 only
        owner_lock_timeout: 5     # seconds a write waits for another one of the same owner (0 = no wait)
        commit_tracking: bundle   # bundle (the connection of doctrine.entity_manager) or off
```

Since 1.7.1 the PostgreSQL owner lock waits `owner_lock_timeout` too: a transaction-local `lock_timeout` is set for the
lock statement only and your previous value is restored right after it (0 uses `pg_try_advisory_xact_lock()`).
Cross-origin API clients that
read the code must list `X-Conflict-Code` in `Access-Control-Expose-Headers` (the bundle's routes are same-origin).

## Entity managers

- **Private manager** (1.8 opt-in, the only mode since 2.0 unless a dedicated manager is named): units use
  transaction-doctrine's `forEntityManager()` on the private manager, which is cleared after a storage failure or
  replaced on its next use when closed. Your managers are never touched.
- **Dedicated manager** (`doctrine.entity_manager: storage_kit`): units use transaction-doctrine's
  `forEntityManager()`, which resets that manager after a storage failure. Your manager is never touched.
- **Your default manager** (the 1.x default, removed in 2.0, REQ-ARCH-001 rule 6): the bundle never reads, writes or
  flushes through it. Its pending changes are no longer written by a storage admin page or command.
- Worker mode with kernel reset false: nothing of a unit is kept on a service; the private manager is dropped and the
  dedicated manager is cleared at every request / message / command boundary ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Tests

- `tests/Integration/Concurrency/ConcurrencyRaceTest.php`: two kernels (two connections) on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16/17 (CI job "Lifecycle on …", required): two first saves of a bucket, a cancel racing the copy runner,
  a real lock wait on the switch (409 + `CONFLICT_001` + `Retry-After`, nothing changed, retry succeeds), two saves of
  the same text reference (one row; the same failure after an earlier commit answers `CONFLICT_002`). Lock waits are
  capped at one second.
- `tests/Integration/Concurrency/FirstUseRaceTest.php` (1.7.1): two kernels race the first use of the instance row and
  of a config profile's row: the row created inside an open transaction (the other connection waits and answers
  `CONFLICT_001`, then reuses it), the row committed just before the owner lock (read again and reused; `GET_LOCK()`
  is re-entrant within one session, so only a second connection proves it), a snapshot older than the commit and a
  loser inside its own transaction (MySQL / MariaDB: retry-safe conflict, never a duplicate, never a reuse), the row
  lock wait bounded by `owner_lock_timeout` with the session value restored, and a PostgreSQL lock timeout inside
  your transaction keeping your write and your `SET LOCAL lock_timeout`.
- `tests/Integration/Functional/ConcurrentWritesTest.php`: the same re-checks on one connection (SQLite and the three
  databases) with a statement hook as the other writer, plus a deadlock on the settings page and on the upload insert.
- `tests/Integration/Concurrency/RowVersionRaceTest.php` (2.1): two editors with the same version on two
  connections (one wins, the other gets the stale 409, the stored row is the winner's); a save waiting on the row lock
  of an open save answers `CONFLICT_001`, then its retry is stale; and (MySQL / MariaDB, audit ZH-M1) a real deadlock
  inside the save's flush, against a second process: the deadlock destroys the ORM's savepoint, the runner repairs
  the nesting level, the page answers 409 `CONFLICT_001` (never a 500), the connection is back at level 0 and the
  next save works.
- `tests/Integration/Functional/StaleEditTest.php` (2.1, SQLite and the database jobs): stale, missing and forged
  versions on the Storage and Move pages, a first save, a bucket created meanwhile, an unchecked save from your code.
- `tests/Unit/Concurrency`, `tests/Unit/EventListener/StorageKitConflictListenerTest.php`,
  `tests/Unit/Doctrine/OwnerLockTest.php`: the mapping (001 / 002, unique violations and lost connections excluded) and
  the lock per platform.

## Residual risks

- **Owner lock inside your transaction.** If you call a Move / Storage write inside a transaction you opened, the
  MySQL / MariaDB lock is released when the bundle's unit ends, before your commit (`GET_LOCK()` is session-scoped).
  The Storage / Move writes are the bundle's own pages, which open no transaction around them. The first-use rows,
  which your code can reach inside its transaction (an upload), are protected since 1.7.1 by the locking re-read: the
  instance's active rows stay locked until your commit, so a concurrent first use (of the instance row or of a config
  profile's row) waits at most `owner_lock_timeout` and answers `CONFLICT_001`. Consequences:
  - **The loser inside a transaction always retries.** On MySQL / MariaDB a first use made inside your own
    transaction that loses the race never reuses the other row: its lookup before the owner lock opened its
    REPEATABLE READ snapshot, which cannot see the other commit, so it fails with a retry-safe
    `TransientStorageFailure` (`CONFLICT_001` on bundle routes) and the retry in a new transaction reuses the row.
  - **Scoped profiles can wait too.** The locking read takes next-key locks on the `scope, state` index around the
    instance's active rows. Creating a scope's first profile (inserting next to that range) can wait on a first-use
    transaction that is still open, up to `innodb_lock_wait_timeout`.
  - **Deadlocks need little.** An empty range (no active instance row yet) plus first uses of different owner keys
    (the instance row and a config profile's row, which take different `GET_LOCK()` names but lock the same range)
    is enough for two first uses to deadlock: both lock the empty gap, then both insert. One gets the deadlock, a
    retry-safe `CONFLICT_001`. No long transaction is needed.

  Rows duplicated by earlier versions: detection and recovery in
  [UPGRADING](UPGRADING.md#duplicate-instance-rows-140161).
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
