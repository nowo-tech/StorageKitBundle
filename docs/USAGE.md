# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [The instance bucket](#the-instance-bucket)
  - [The `storage` settings section](#the-storage-settings-section)
  - [A bucket from the configuration](#a-bucket-from-the-configuration)
  - [Providers](#providers)
  - [Test connection](#test-connection)
- [File contexts (who may write and read)](#file-contexts-who-may-write-and-read)
- [Uploading from an editor](#uploading-from-an-editor)
  - [MilkdownEditor](#milkdowneditor)
  - [Any other editor](#any-other-editor)
  - [Upload checks and answers](#upload-checks-and-answers)
- [Rendering texts with images](#rendering-texts-with-images)
- [Downloads](#downloads)
  - [CSP for redirect-delivered profiles](#csp-for-redirect-delivered-profiles)
- [References and the purge](#references-and-the-purge)
- [Scope buckets](#scope-buckets)
  - [Import from AWS CSV](#import-from-aws-csv)
- [Upload limits](#upload-limits)
- [Moving to another bucket](#moving-to-another-bucket)
  - [Move page](#move-page)
  - [nowo:storage-kit:sync](#nowostorage-kitsync)
  - [Switching the settings-backed instance bucket](#switching-the-settings-backed-instance-bucket)
- [Adopting an existing instance bucket](#adopting-an-existing-instance-bucket)
- [Personal data](#personal-data)
- [Audit events](#audit-events)
- [Admin UI](#admin-ui)
  - [Forms (FormKit)](#forms-formkit)
  - [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations](#translations)
- [Doctrine schema and migrations](#doctrine-schema-and-migrations)

## How it fits together

- **Texts** (comments, descriptions, wiki pages…) are your entities. They point at uploaded images with a URI
  `storage-file:<uuid>` (`uri_scheme`) or with the download path `/storage/files/<uuid>/<variant>`, never with a
  bucket URL.
- **File contexts** (`FileContextInterface`, your code) say who may upload into a text's context and who may read it.
- **Profiles** (`StorageProfile` rows) are the buckets. An owner — the instance, or a scope such as an organisation —
  has one *active* profile (where uploads go), at most one *target* (a move in progress) and any number of *retired*
  ones (buckets moved away from). Every stored file remembers its profile, so downloads, purges and moves always use
  the right bucket.
- **Stored files** (`StoredFile` rows) are the uploaded images: original plus `preview` / `thumb` variants, in a private
  bucket under a random key `yyyy/mm/<uuid>`.
- **References** (`StoredFileReference` rows) record which text points at which file; a file no text points at is an
  orphan, deleted by `nowo:storage-kit:purge` after `purge.retention_days`.

## The instance bucket

### The `storage` settings section

With the default `instance_profile: null`, the instance bucket is the `storage` section of
[Instance Settings](https://github.com/nowo-tech/InstanceSettingsBundle) (**Settings → Storage**,
`/admin/settings/sections/storage` with its defaults):

1. Choose a **provider** (empty = uploads off). Only the provider's fields are shown and saved; the other provider's
   values are kept but unused ([CONFIGURATION](CONFIGURATION.md#the-storage-instance-settings-section)).
2. Fill in region, bucket, prefix, access key and secret (and, for an S3-compatible service, the endpoint, the
   optional public endpoint and path style). The secret is an Instance Settings secret: encrypted at rest, never
   rendered back; leave it blank to keep the saved one. For Amazon S3 you can instead upload the access-key CSV of the
   AWS console ([Import from AWS CSV](#import-from-aws-csv)).
3. Press **Test connection** (posts to the kit and comes back to the section with the result).

The section shows notices when uploads are off, when the connection is incomplete and when the last test failed.
**Usage and move** opens the instance Storage page (`/admin/storage`): status, usage (files and bytes), last test,
**Test connection**, **Edit connection** (back to the section) and **Move to another bucket**.

Saving the section dispatches Instance Settings' `SettingsChangedEvent`; `InstanceSettingsListener` drops the memoised
profiles and connections (the next request uses the new values in every worker), forgets the last test result (it
tested the old connection) and records a `profile_saved` audit event with the changed field names.

The first time a provider is selected, the kit creates the instance's active `StorageProfile` row (`source =
settings`): it holds no connection data, it only gives files a profile to point at.

### A bucket from the configuration

Operations can pin the instance bucket in the configuration instead (credentials from the environment):

```yaml
nowo_storage_kit:
    profiles:
        main:
            dsn: '%env(STORAGE_KIT_DSN)%'   # s3://KEY:SECRET@s3.eu-central-003.backblazeb2.com/my-bucket?region=eu-central-003
            delivery: redirect              # or proxy
    instance_profile: main
```

The `storage` section is then not registered, the instance Storage page is read-only (Test and Move work, *Switch*
does not: change the configuration instead) and the instance does not need Instance Settings. Config profiles are
trusted: the SSRF guard does not apply to them, and only they can use `delivery: redirect`.

### Providers

| Provider | Value | Fields |
| --- | --- | --- |
| Amazon S3 | `aws_s3` | region, bucket, prefix, access key, secret. The endpoint is derived from the region (`s3.<region>.amazonaws.com`, virtual-hosted style). |
| S3-compatible service | `s3_compatible` | the same plus endpoint (`https://host[:port]`, SSRF-guarded), optional public endpoint (when browsers reach the bucket at another address than the server), path style (most self-hosted services: MinIO, RustFS). Cloudflare R2, Backblaze B2, Wasabi, Scaleway, RustFS, MinIO… |

Objects are always written private (canned ACL `bucket-owner-full-control`, accepted by buckets with ACLs disabled).
There is no local-disk provider in 1.0.

### Test connection

*Test connection* (instance Storage page, the `storage` section, a scope page, the Move page for the target) checks the
endpoint with the SSRF guard, writes a small object under `<prefix>/.storage-kit-probe/`, reads it back, pre-signs it,
tries an anonymous read and deletes it. The flash names the failed step and a generic reason (*the storage answered
with an error (4xx)*, *could not be reached*, *the endpoint is not allowed*…); the provider's own message only goes to
the log, scrubbed of credentials and signatures. If the anonymous read succeeds the test passes with a warning: *anyone
can read this bucket. Make it private before storing files.* The result is stored on the profile (aside "Connection
test") and audited (`profile_tested`).

## File contexts (who may write and read)

Uploads are refused until you register at least one context. A context is a kind of text files are uploaded into;
implement `Nowo\StorageKitBundle\Context\FileContextInterface` (autoconfigured, tag `nowo_storage_kit.file_context`):

```php
use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContextTarget;

final class IssueCommentContext implements FileContextInterface
{
    public function __construct(private IssueAccess $access, private IssueRepository $issues) {}

    public function type(): string { return 'issue'; }        // 1–16 chars [a-z0-9_], stored on the file

    /** Context ids the viewer may read: ONE query for all ids (a page renders many images). */
    public function readable(?object $viewer, array $ids): array
    {
        return $this->access->readableIssueIds($viewer, $ids);
    }

    /** Which contexts still exist (files of a deleted one become orphans). */
    public function existing(array $ids): array
    {
        return $this->issues->existingIds($ids);
    }

    /** Null = unknown or not writable (answered 404); else the scope bucket and the usage owner. */
    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        $issue = $this->issues->find($id);

        return $issue !== null && $this->access->canComment($user, $issue)
            ? new FileContextTarget(scope: $issue->getOrganisationKey(), owner: $issue->getProjectKey())
            : null;
    }
}
```

- Context ids: 1–64 characters `[A-Za-z0-9_.:-]` (they appear in URLs and CSRF token ids).
- `FileContextTarget::$scope` picks the scope bucket (only with `scopes.enabled`; null = the instance);
  `$owner` (≤ 64 characters) is the key usage and limits are counted on.
- Read decisions are memoised per viewer and context for the request (reset between worker requests).
- An unknown context type reads nothing and writes nothing.

## Uploading from an editor

### MilkdownEditor

`nowo_storage_milkdown_upload(type, id)` returns MilkdownEditorBundle 1.1 upload options (`upload_url`,
`upload_csrf_token`, `upload_max_size`, `upload_accept`), or `[]` when uploads are not on in that context:

```twig
{{ form_row(form.body, nowo_storage_milkdown_upload('issue', issue.id)) }}
```

Or in PHP: `UploadEndpoint::milkdownOptions('issue', $id)` merged into the field options.

### Any other editor

`nowo_storage_upload(type, id)` returns the upload state of a context for the current user:

- `{state: 'on', url, token, header: 'X-CSRF-Token', field: 'file', max_bytes, accept}`;
- `{state: 'off', setup_url}` — the user may write there but no bucket is set up (`setup_url` points at the Storage
  page the user may manage, or is null);
- `{state: 'none'}` — not signed in, no upload role, or not writable.

```js
const body = new FormData();
body.append(config.field, file);
const res = await fetch(config.url, { method: 'POST', headers: { [config.header]: config.token }, body });
const json = await res.json();   // 201: insert json.markdown; else show json.message
```

### Upload checks and answers

`POST <upload.path_prefix>/upload/{type}/{id}` (multipart field `file`, token in `X-CSRF-Token`, token id
`nowo_storage_kit/upload/<type>/<id>`) checks, in order: CSRF, signed-in user with `upload.access_roles`, per-user rate
limit, the PHP upload, `max_bytes`; then the context (`writableTarget()`), a usable bucket, the image (content sniffing:
PNG, JPEG, GIF, WebP only; SVG / HTML refused; pixels before decoding; memory), the owner's limit. JPEG, PNG and still
WebP are re-encoded (EXIF / GPS / XMP dropped, JPEGs turned upright); GIF and animated WebP are kept as uploaded.

| Status | `error` | Meaning |
| --- | --- | --- |
| 201 | — | `{uuid, uri, url, original_url, name, alt, image: true, markdown}` — `markdown` is `![alt](storage-file:<uuid>)`, `url` the relative preview download path |
| 403 | `csrf`, `access`, `limit` | bad token, no upload role, owner limit reached |
| 404 | `context` | unknown or not writable context |
| 409 | `off` | no usable bucket for the context |
| 413 | `size` | above `max_bytes` (or PHP's own limits) |
| 422 | `type`, `pixels`, `empty` | not an accepted image, too many pixels / too large to decode, no file |
| 429 | `rate` | rate limited |
| 503 | `storage` | the bucket failed (objects already written are removed) |

`message` is the translated `upload.error.<reason>`. Answers are `private, no-store`, `nosniff`. A refused upload
stores nothing. The PHP API behind it is `Upload\ImageUploader::upload($user, $type, $id, $bytes, $name)`.

A new file is an orphan until a text that points at it is saved ([References](#references-and-the-purge)).

## Rendering texts with images

`nowo_storage_images(text)` (or `StoredImageResolver::resolveText($text, $viewer)`) returns the stored images of a text
the current viewer may read, `uuid => StoredImage{src, original, width, height, alt}` — one query for the files and one
batched read check per context type. Images the viewer may not read are left out: render a neutral placeholder.

```twig
{% set images = nowo_storage_images(comment.body) %}
{# in your Markdown renderer: replace storage-file:<uuid> with images[uuid].src, link to images[uuid].original,
   set width / height to avoid layout shift #}
```

`FileUris::storedId($url)` turns `storage-file:<uuid>` or a download path into the uuid; `FileUris::plainText($text)`
replaces inline images with `[image]` for texts sent outside the application (e-mails, push notifications).
`nowo_storage_file_url(uuid, variant = 'preview')` gives a download path (the endpoint still checks access).

## Downloads

`GET <upload.path_prefix>/files/{uuid}/{original|preview|thumb}` answers **404** unless the viewer may read the file's
context — the same answer for a missing and a forbidden file; anonymous viewers are asked to the context too. A missing
variant falls back to the original. Then:

- **proxy** (every database / settings bucket, and config profiles with `delivery: proxy`): the application streams the
  object as it arrives from a ranged `GetObject` (`Range: bytes=0-<stored size − 1>`; `Content-Length` = the size
  stored at upload; a bucket that sends more is cut there) with the
  stored type, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`,
  `Cross-Origin-Resource-Policy: same-origin` and `Content-Disposition` (`download.disposition`, default `attachment`,
  which `<img>` still renders);
- **redirect** (config profiles with `delivery: redirect`): `302` to a pre-signed URL of the private bucket, valid
  `download.url_ttl` seconds; the bucket answers with the stored type, the configured disposition and a private cache.

Both are `Cache-Control: private, max-age=<url_ttl − 60>`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.

### CSP for redirect-delivered profiles

`Http\StorageCspSources::imgSrc()` lists the browser origins of the config profiles with `delivery: redirect` (never a
bucket typed into a page). With `nowo-tech/http-hardening-bundle` installed, `StorageCspSourceProvider` adds them to the
CSP `img-src` automatically; otherwise add them to your own policy. With no redirect profile, nothing is added.

## References and the purge

For each kind of text that can point at files, implement `Reference\FileReferenceSourceInterface` (autoconfigured, tag
`nowo_storage_kit.reference_source`):

```php
use Nowo\StorageKitBundle\Reference\ExistingIds;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;

final class CommentReferenceSource implements FileReferenceSourceInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function type(): string { return 'comment'; }                 // 1–16 chars [a-z0-9_]
    public function entityClass(): string { return Comment::class; }
    public function field(): string { return 'body'; }                   // a flush that does not change it is free
    public function id(object $entity): string { return $entity->getUuid(); }   // known before the insert
    public function text(object $entity): string { return $entity->getBody(); }
    public function context(object $entity): ?array { return ['issue', $entity->getIssue()->getId()]; }
    public function existing(array $ids): array { return ExistingIds::of($this->em, Comment::class, 'uuid', $ids); }
}
```

On every Doctrine flush, `FileReferenceListener` collects the texts written, changed or removed whose old or new value
mentions a stored file, and updates their references after the flush (texts without files cost no query). Only files
of the text's **own context** are referenced: a uuid copied from elsewhere cannot keep someone else's orphan alive.
At most 200 file ids are taken from one text.

Schedule the purge daily:

```bash
php bin/console nowo:storage-kit:purge            # retention: purge.retention_days (7)
php bin/console nowo:storage-kit:purge --days=30
```

It runs four bounded steps in keyset batches (`purge.batch_size`, at most `purge.max_batches` per step and run): drop
references of texts that no longer exist (SQL cascades), drop references of files whose context is gone, mark
unreferenced files as orphans, delete orphans older than the retention (objects from their own bucket, then the row; a
bucket that refuses keeps the row for the next run; a file that got a reference in between is never deleted). Output:
`References of deleted texts: …; files of deleted contexts: …; new orphans: …; purged: …; failed: ….` — exit 1 when a
deletion failed. Audited as `files_purged`.

## Scope buckets

Let organisations (tenants, workspaces…) bring their own bucket:

```yaml
nowo_storage_kit:
    scopes:
        enabled: true
        access_checker: App\Storage\OrganisationStorageAccess   # ScopeAccessCheckerInterface
        resolver: null                                          # default: FileContextTarget::$scope
        path_prefix: /storage/scopes
```

- `ScopeAccessCheckerInterface::canManage(?object $user, string $scope): bool` decides who opens
  `/storage/scopes/{scope}` and `/storage/scopes/{scope}/move`; everyone else gets **404**.
- `ScopeResolverInterface::scopeOf(FileContextTarget $target): ?string` maps a context target to a scope key
  (`[A-Za-z0-9_.:-]{1,64}`); the default returns `$target->scope`.
- Resolution: the scope's active profile; a scope profile that exists but is turned off or incomplete turns that
  scope's uploads **off** (no silent fallback to another owner's bucket); a scope without a profile uses the instance
  bucket.

The scope Storage page has the bucket form (`StorageProfileType`): provider, *Store uploads in this bucket* switch,
the provider's fields (endpoint rows only for an S3-compatible provider, through `visible-when`), secret write-only
(blank keeps it, encrypted by DoctrineEncrypt). Turning uploads on needs a bucket, an access key, a secret and, for an
S3-compatible provider, an endpoint. Scope endpoints always go through the SSRF guard. Saving records `profile_saved`
with the changed field names.

### Import from AWS CSV

For **Amazon S3**, every bucket form (scope Storage page, Move target, the `storage` section) also accepts the
access-key CSV the AWS console offers when you create an access key (*IAM → Users → Security credentials → Create
access key → Download .csv file*, named `<user>_accessKeys.csv`) instead of typing the access key and the secret:

```csv
Access key ID,Secret access key
AKIAIOSFODNN7EXAMPLE,wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

- Pick the file in **Import from AWS CSV** and save. The access key and the secret are taken from the file; the other
  fields (region, bucket, prefix…) are typed as usual. The older 5-column export
  (`User name,Password,Access key ID,Secret access key,Console login link`), a UTF-8 BOM and Windows line ends are
  accepted.
- **Either the file or the fields.** Leave the access key and secret fields as they are (the access key row shows the
  saved key, which the file replaces: key rotation). If you type a secret, or change the access key, to a value that
  differs from the file, the form refuses it (*differs from the CSV file*) rather than guessing which one you meant;
  the same value typed and in the file is fine.
- The file is checked on the server: at most 4 KB, UTF-8 text (the browser's declared type is ignored — Windows sends
  `application/vnd.ms-excel` for `.csv`), an `Access key ID` and a `Secret access key` column (any case, any order,
  other columns allowed), exactly one data row, a key id `AKIA…` / `ASIA…` of 20 characters and a 40-character secret.
  Each refusal is a translated error under the row that never repeats the file's content.
- The file is read once from PHP's temporary upload and discarded with the request: it is never moved, stored, logged
  or audited, and the secret is never rendered back (the audit event lists `access_key` / `secret` as changed field
  names only). If another field is invalid, pick the file again: like a typed secret, it is not kept between attempts.
- The row is shown only for the `aws_s3` provider (`visible-when`); a file sent with `s3_compatible` is ignored, like
  every hidden row. It needs no JavaScript.

## Upload limits

```yaml
nowo_storage_kit:
    limits:
        upload_limit: App\Storage\PlanQuota   # UploadLimitInterface
```

`UploadLimitInterface::allows(FileContextTarget $target, int $bytes): bool` is asked after the image checks and before
anything is written (`$bytes` = original + variants). `StoredFileRepository::bytesFor(array $owners)` gives the current
usage of owners.

## Moving to another bucket

### Move page

*Move to another bucket* (`/admin/storage/move`, or `/storage/scopes/{scope}/move`) moves every file of an owner:

1. **Save the new bucket** (same form as a scope bucket; it is the owner's *target* profile) and **Test** it.
2. **Start copying**: with a Messenger bus the copy runs in the background (`RunStorageSyncMessage`, slices of
   `sync.slice_seconds`); without one the page shows the command to run. Every object (original and variants) is read
   back and compared by SHA-256 (the original also against the checksum stored at upload); an object already in the
   target with the same checksum is not written again. The state is saved after every file: a stopped copy
   **resumes** after the last verified file. The first failure stops it with the file and a generic reason.
3. **Stop copying** cancels; **Resume copying** continues; **Copy new files** catches up files uploaded meanwhile.
4. **Switch to the new bucket** is enabled only after a complete, verified copy with no file uploaded since: in one
   transaction every file is re-pointed to the target, the target becomes active and the old profile is **retired**
   (kept, so a file written there during the switch still downloads). Objects are never deleted from the old bucket.
5. **Forget the new bucket** discards the target and its sync (objects already copied stay in it).

Every action is a POST with a CSRF token bound to the action and the owner, and is audited (`move_target_saved`,
`move_sync_started`, `move_sync_cancelled`, `move_switched`, `move_target_discarded`). A configuration-managed instance
bucket (`instance_profile`) can be copied but not switched here.

### nowo:storage-kit:sync

```bash
php bin/console nowo:storage-kit:sync <from-uuid> <to-uuid>          # copy, verified, resumable (progress bar)
php bin/console nowo:storage-kit:sync <from-uuid> <to-uuid> --check  # compare only
php bin/console nowo:storage-kit:sync <uuid> <uuid> --check          # one bucket against its rows
```

The UUIDs are shown on the Move page. The command resumes the same sync as the page; it refuses to run a sync another
runner holds. `--check` lists objects of the source's files missing from the target and objects under the target's
prefix no file knows (first 100 of each; the scan stops after 100 000 objects) and exits 1 when something is missing.
*Switch* stays on the Move page.

### Switching the settings-backed instance bucket

When the instance bucket is the `storage` section, *Switch* writes the target's connection into the section (validated
by Instance Settings, the secret encrypted by it) and the target row reads from the section from then on. The retired
row keeps an encrypted copy (DoctrineEncrypt) of the old connection, so files still pointing at it keep downloading.
If the section refuses the target's connection at that moment (an allow-list or endpoint rule changed since the target
was saved), the page says so and nothing changes. Do not edit the `storage` section while a move is in progress: the
switch replaces it with the target.

### Forgetting previous buckets

Retired profiles keep their encrypted credentials while files still point at them. When none does any more,
*Forget previous buckets* on the Move page (POST + CSRF) deletes them, or from the console:

```bash
php bin/console nowo:storage-kit:forget-retired --dry-run     # list the instance's forgettable profiles
php bin/console nowo:storage-kit:forget-retired --scope=acme  # one scope
php bin/console nowo:storage-kit:forget-retired --all         # every owner
```

Objects in those buckets are never deleted; each run is audited as `profiles_forgotten`. The file foreign key protects a
profile a file was repointed at meanwhile (MySQL / MariaDB / PostgreSQL refuse the delete; on SQLite enable foreign
keys) — see [SECURITY](SECURITY.md#residual-risks-and-host-guidance).

## Adopting an existing instance bucket

`nowo:storage-kit:adopt-instance-bucket` migrates an instance bucket kept as a database row (`source = database`,
state `active`, scope null — typically rows imported from an application's own storage tables, such as Beacon's) into
the `storage` Instance Settings section: the row's connection is written into the section and the row becomes the
settings-backed active profile, so the files keep pointing at it. Use it once after importing the rows (see
[UPGRADING](UPGRADING.md#migrating-from-an-applications-own-storage-code)). Until then, such a row stays the instance's
active profile and the values saved in the section are not used.

## Personal data

`PersonalData\StoredFilesPersonalData` is the hook for your GDPR export / erasure (UserKit's eraser / export provider,
or your own), keyed by the user identifier stored at upload:

- `export(string $userIdentifier): list<array>` — uuid, name, type, size, dimensions, sha256, context, whether a text
  still shows it, created at and an absolute link to the original (it answers while the user may still read the
  context);
- `erase(string $userIdentifier): int` — files a kept text still shows lose their name and uploader; files no text
  shows are expired for the next purge. No bucket call (erasers usually run in one transaction). Audited as
  `files_erased`.

## Audit events

`Audit\StorageAuditEvent` is dispatched after every mutation (`action`, `scope`, `actor` user identifier, `details`) and
logged at `info`. It never carries a secret, an access key, a signed URL or a provider message.

| Action | Details |
| --- | --- |
| `profile_saved` | profile, fields (names only), origin (section saves) |
| `profile_tested` | profile, ok, step, reason |
| `move_target_saved` / `move_target_discarded` | profile (and fields) |
| `move_sync_started` | sync, from, to |
| `move_sync_cancelled` | sync |
| `move_switched` | from, to, files |
| `profiles_forgotten` | profiles (UUIDs of the retired profiles deleted) |
| `file_uploaded` | file, context, bytes |
| `files_purged` | counters |
| `files_erased` | files |

```php
#[AsEventListener]
public function __invoke(StorageAuditEvent $event): void
{
    $this->auditLog->add('storage.' . $event->action, $event->actor, $event->details);
}
```

## Admin UI

| Page | Path (defaults) | Access |
| --- | --- | --- |
| Instance Storage | `/admin/storage` | `security.access_roles` / `access_checker` (listener before the controller + controller) |
| Instance Move | `/admin/storage/move` | same |
| Scope Storage | `/storage/scopes/{scope}` | `scopes.access_checker` (404 otherwise) |
| Scope Move | `/storage/scopes/{scope}/move` | same |
| `storage` section | Instance Settings section page | Instance Settings access rules |

Pages are UiKit form scaffolds ([TEMPLATES](TEMPLATES.md)); every response is `private, no-store`; secrets are never
part of a view model.

### Forms (FormKit)

`StorageProfileType` and the CSRF-only action forms are FormKit types rendered with `form_start` / `form_end`
(REQ-TWIG-005) — no raw `<form>` / `<input>` in the bundle templates; keep it that way in overrides. Profile:
`nowo_form_kit.profiles.storage_kit` ([CONFIGURATION](CONFIGURATION.md#formkit-profile)).

### Overriding templates (REQ-TWIG-001)

Restyle through `nowo_ui_kit.theme` first. When a page needs bundle-specific markup, **do not copy** the template:
point `web_ui.templates.<page>` at a thin template that extends the original and overrides one block:

```yaml
nowo_storage_kit:
    web_ui:
        templates:
            move: 'admin/storage/move.html.twig'
```

```twig
{# templates/admin/storage/move.html.twig #}
{% extends '@!NowoStorageKitBundle/move/edit.html.twig' %}
{% block nowo_storage_kit_move_progress %}{{ parent() }}<p>Copies run every night.</p>{% endblock %}
```

`templates/bundles/NowoStorageKitBundle/<path>` overrides still work (Symfony convention) but freeze the file: re-apply
upstream changes by hand, or replace them with a `web_ui.templates` override. Recovery: delete the copy, clear the
cache, re-apply only the block you needed.

| Template (subpath) | Page id | Scaffold | Blocks |
| --- | --- | --- | --- |
| `settings/edit.html.twig` | `settings` | `@NowoUiKit/page/form.html.twig` | `nowo_ui_form_body`, `nowo_storage_kit_settings_notice`, `nowo_ui_form_aside` |
| `move/edit.html.twig` | `move` | `@NowoUiKit/page/form.html.twig` | `nowo_ui_form_aside`, `nowo_storage_kit_move_progress` |
| `settings/_aside.html.twig` | — | partial | aside cards of both pages |

The `storage` section page itself belongs to Instance Settings.

## Translations

Domain **`NowoStorageKitBundle`** in `en`, `es`, `fr`, `de`, `it`, `pt`, `nl`: page labels, field labels and help,
validation messages, test outcomes (`test.*`, `probe.*`), move statuses (`move.*`), upload errors (`upload.error.*`),
providers (`provider.*`) and the section (`section.*`). Override any key in
`translations/NowoStorageKitBundle.<locale>.yaml`.

## Doctrine schema and migrations

Entities `StorageProfile`, `StoredFile`, `StoredFileReference` and `StorageSync` are mapped automatically. Generate a
migration in your app:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Tables and indexes: [ARCHITECTURE](ARCHITECTURE.md#entities-and-tables).
