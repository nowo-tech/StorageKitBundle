# Configuration

Root key: `nowo_storage_kit`. Every node is strict (unknown keys are refused) and validated when the container is
compiled; the defaults are the safe choice. Source of truth: `src/DependencyInjection/Configuration.php`.

## Table of contents

- [Full reference](#full-reference)
- [profiles and instance_profile](#profiles-and-instance_profile)
  - [config_profile_provider (1.4)](#config_profile_provider-14)
- [uri_scheme](#uri_scheme)
- [endpoints (SSRF guard)](#endpoints-ssrf-guard)
- [upload](#upload)
- [download](#download)
- [purge](#purge)
- [sync](#sync)
- [scopes](#scopes)
- [limits](#limits)
- [personal_data](#personal_data)
- [doctrine](#doctrine)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host (and new installs)](#ddd--hexagonal-host-and-new-installs)
- [web_ui](#web_ui)
- [security](#security)
- [The `storage` Instance Settings section](#the-storage-instance-settings-section)
- [FormKit profile](#formkit-profile)
- [Messenger](#messenger)
- [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [Container parameters and services](#container-parameters-and-services)
- [Fail-closed checks](#fail-closed-checks)

## Full reference

```yaml
# config/packages/nowo_storage_kit.yaml (defaults shown)
nowo_storage_kit:
    profiles: {}                 # name => bucket set by operations (see below)
    config_profile_provider: null # 1.4: service id of your ConfigProfileProviderInterface (replaces "profiles")
    instance_profile: null       # null = the instance bucket is the "storage" Instance Settings section
    uri_scheme: storage-file     # texts keep ![alt](storage-file:<uuid>)
    endpoints:
        guard_instance_profile: true
        allowed_hosts: []
        allow_private: false     # kernel.debug only
        resolve_dns: true
        dns_timeout: 2.0         # seconds, 0.1–10
        request_timeout: 30.0    # seconds, 1–300 (total bounded at 4×)
    upload:
        enabled: true
        path_prefix: /storage
        access_roles: [ROLE_USER]
        max_bytes: 10485760      # 10 MiB, 1024 – 50 MiB
        max_pixels: 24000000     # 1 – 100 000 000
        variants: { preview: 1600, thumb: 400 }
        rate_limit: 30           # uploads per user and window, 1–1000
        rate_interval: '1 minute'
    download:
        url_ttl: 300             # seconds, 60–3600
        disposition: attachment  # attachment | inline
    purge:
        retention_days: 7        # 1–365
        batch_size: 500          # 10–5000
        max_batches: 200         # 1–10 000
    sync:
        batch_size: 50           # 1–500
        slice_seconds: 240       # 10–3600
        stale_seconds: 120       # 30–3600
    scopes:
        enabled: false
        resolver: null           # service id (ScopeResolverInterface)
        access_checker: null     # service id (ScopeAccessCheckerInterface), required when enabled
        path_prefix: /storage/scopes
    limits:
        upload_limit: null       # service id (UploadLimitInterface)
    personal_data:
        uploader_key_resolver: null # service id (UploaderKeyResolverInterface); null = <user class>:<getId()>
        user_provider: security.user_providers # used by nowo:storage-kit:backfill-uploader-keys
    doctrine:
        table_prefix: ''
        entity_manager: null      # 1.5: a dedicated manager name (recommended for new installs; the 2.0 default)
    web_ui:
        enabled: true
        path_prefix: /admin/storage
        layout_template: null    # null = nowo_ui_kit.layouts.admin
        templates: {}            # settings | move => template
    security:
        access_roles: [ROLE_ADMIN]
        access_checker: null     # service id (StorageAccessCheckerInterface)
        allow_unauthenticated: false   # kernel.debug only
```

The switches `upload.enabled`, `scopes.enabled`, `web_ui.enabled`, `security.allow_unauthenticated`,
`endpoints.allow_private`, `endpoints.guard_instance_profile` and `endpoints.resolve_dns` must be **literal booleans**:
an `%env(bool:…)%` placeholder is refused (it would read as true at compile time).

## profiles and instance_profile

> **Ops-only.** The primary place for bucket credentials is the database, encrypted: the `storage` Instance Settings
> section for the instance bucket and the `storage_kit_profile` rows for scope / move buckets, both edited in the admin
> pages. `profiles` (a `dsn` from an environment variable such as `STORAGE_KIT_DSN`, or fields) is for **infrastructure
> buckets managed outside the application** by operations — for example a bucket provisioned with the platform whose
> credentials live in the deployment's secret store. It is supported and not deprecated, but an application should not
> need it to store uploads.

`profiles` holds buckets set by operations — trusted configuration, **not** checked by the SSRF guard. Names: 1–64
characters `[a-z0-9_]`. Each entry needs either a `dsn` or `bucket` + `access_key` + `secret`:

| Key | Default | Notes |
| --- | --- | --- |
| `dsn` | `null` | `s3://KEY:SECRET@host[:port]/bucket[/prefix]?region=…&path_style=1&tls=0&public_endpoint=…` (credentials URL-encoded). Host `aws` or `s3.amazonaws.com` = AWS itself; `tls=0` = `http://`. Use `%env(STORAGE_KIT_DSN)%`. |
| `endpoint` | `null` | `http(s)://host[:port]`, no path / query / credentials; null = AWS (derived from the region) |
| `public_endpoint` | `null` | Address browsers follow for pre-signed URLs when it differs from `endpoint` |
| `region` | `us-east-1` | `[a-z0-9-]` token, ≤ 32 characters |
| `bucket` | `null` | S3 naming rules: 3–63 lowercase letters, digits, dots, dashes; no `..` |
| `prefix` | `''` | Key prefix: `[A-Za-z0-9!_.*'()-/]`, ≤ 191 characters, no `.` / `..` segment |
| `access_key` | `null` | 1–191 printable characters, no spaces |
| `secret` | `null` | 1–255 printable characters |
| `path_style` | `false` | Bucket in the path instead of the host name |
| `delivery` | `redirect` | `redirect`: downloads answer `302` to a pre-signed URL of the bucket (its origin goes into the CSP `img-src`, see [USAGE](USAGE.md#csp-for-redirect-delivered-profiles)); `proxy`: the application streams the bytes |

A malformed entry (for example an empty env var) does not break the request: the profile is unusable (uploads off) and
the log names the profile, never the value.

`instance_profile` names a `profiles` entry that **is** the instance bucket. The instance Storage page is then
read-only (Test and Move are available, Switch is not) and the `storage` Instance Settings section is **not**
registered. With `instance_profile: null` (default) the instance bucket is the `storage` section and
`nowo-tech/instance-settings-bundle` must be registered. An unknown name fails the compilation.

```yaml
nowo_storage_kit:
    profiles:
        main:
            dsn: '%env(STORAGE_KIT_DSN)%'
            delivery: proxy
    instance_profile: main
```

Credentials belong in environment variables / Symfony secrets, never in committed YAML.

### config_profile_provider (1.4)

Service id of your `Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface`: the named buckets then come from
it (a secret manager, your own tenant configuration) instead of the `profiles` tree, which must stay empty (the two are
exclusive). `instance_profile` may name one of its buckets; the name is checked at runtime (unknown = uploads off), not
at compilation. Same trust as `profiles`: no SSRF guard, and a `redirect` bucket adds its origin to the CSP. A name
must keep meaning the same bucket; methods are called per use (worker mode: keep no request state in the service).

```yaml
nowo_storage_kit:
    config_profile_provider: App\Infrastructure\Storage\VaultBuckets
    instance_profile: primary
```

## uri_scheme

Scheme of the URIs kept in texts: `storage-file` gives `![alt](storage-file:<uuid>)`. 2–32 characters: a lowercase
letter, then letters, digits, `+`, `.` or `-` (`/^[a-z][a-z0-9+.\-]{1,31}$/`). Keep the scheme your texts already use
when you migrate (for example `beacon-file`). The download path `<upload.path_prefix>/files/<uuid>/<variant>` is
recognised as a reference too.

## endpoints (SSRF guard)

Applies to buckets typed into a page: every scope bucket, every Move target and the instance bucket of the `storage`
section (unless `guard_instance_profile: false`). Config `profiles` are never guarded (ops configuration).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `guard_instance_profile` | `true` | `false` = the instance bucket (section, Move target of the instance) may use private endpoints and a plain HTTP client. Literal boolean. |
| `allowed_hosts` | `[]` | Exact, case-insensitive host names (or IP literals) accepted even when private or single-label (`rustfs`, `minio.internal`). Cloud metadata stays refused, also at connect time. |
| `allow_private` | `false` | Accept private / loopback endpoints. **Refused unless `kernel.debug` is true.** Metadata stays refused. |
| `resolve_dns` | `true` | Resolve endpoint names; refuse when any A / AAAA answer is private, reserved or metadata, or when the name does not resolve. Literal boolean. |
| `dns_timeout` | `2.0` | Deadline of one DNS lookup (seconds, 0.1–10). |
| `request_timeout` | `30.0` | Idle timeout of one storage request (seconds, 1–300); the total duration is bounded at four times this. Applies to every bucket, guarded or not. |

## upload

Upload and download endpoints: `POST <path_prefix>/upload/{type}/{id}` and `GET <path_prefix>/files/{uuid}/{variant}`.

| Key | Default | Validation / effect |
| --- | --- | --- |
| `enabled` | `true` | `false` = no upload / download routes or controllers (the PHP API stays). Literal boolean. |
| `path_prefix` | `/storage` | Absolute path `^/[A-Za-z0-9_\-/.]*$`; must not be under `web_ui.path_prefix`. |
| `access_roles` | `[ROLE_USER]` | Roles allowed to upload at all (any of them); never empty. The file context then decides where. |
| `max_bytes` | `10485760` | Largest upload in bytes (1024 – 52428800). Keep PHP `upload_max_filesize` / `post_max_size` above it. |
| `max_pixels` | `24000000` | Largest image (width × height), read from the header before decoding (1 – 100 000 000). Every JPEG is also budgeted about 6 bytes per pixel of (progressive) decoder memory: see [SECURITY](SECURITY.md#size-pixel-and-memory-limits). |
| `variants` | `{preview: 1600, thumb: 400}` | Longest side per variant (16–8192 px). Names `preview` and `thumb` only; a variant is made only for larger images. |
| `rate_limit` | `30` | Uploads one user may make per window (1–1000, sliding window over `cache.app`). |
| `rate_interval` | `'1 minute'` | Relative time (`strtotime('+…')` must give a positive offset). |

## download

| Key | Default | Validation / effect |
| --- | --- | --- |
| `url_ttl` | `300` | Lifetime of a pre-signed URL (redirect-delivered profiles), 60–3600 s. Responses are `private, max-age = url_ttl − 60`. |
| `disposition` | `attachment` | `attachment` or `inline`. `attachment` still renders in `<img>`; streamed files are always sandboxed and `nosniff`. |

## purge

`nowo:storage-kit:purge` (schedule it daily).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `retention_days` | `7` | Days an orphan (no text points at it) is kept (1–365). `--days` overrides it per run (0–3650). |
| `batch_size` | `500` | Rows per statement (keyset batches, 10–5000). |
| `max_batches` | `200` | Batches per step and run (1–10 000): a large backlog is spread over runs. |

## sync

Bucket moves (Move page and `nowo:storage-kit:sync`).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `batch_size` | `50` | Files read per query (1–500). |
| `slice_seconds` | `240` | Seconds one Messenger message runs a sync before it queues the next slice (10–3600). |
| `stale_seconds` | `120` | A running sync not updated for this long is taken over (its runner died); also the lock TTL (30–3600). |

## scopes

Buckets per scope (organisation, tenant, workspace…).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `enabled` | `false` | Scope resolution and the scope Storage / Move pages. Literal boolean. |
| `resolver` | `null` | Service id implementing `Scope\ScopeResolverInterface`; null = `TargetScopeResolver` (the scope the file context reports). |
| `access_checker` | `null` | Service id implementing `Scope\ScopeAccessCheckerInterface`. **Required when `enabled`** (otherwise `DenyAllScopeAccessChecker`: nobody manages a scope). |
| `path_prefix` | `/storage/scopes` | Absolute path; must not be under `web_ui.path_prefix`. Pages: `<prefix>/{scope}`, `<prefix>/{scope}/move`. |

## limits

| Key | Default | Effect |
| --- | --- | --- |
| `upload_limit` | `null` | Service id implementing `Upload\UploadLimitInterface` (a plan quota per owner), asked before anything is written. Null = only `upload.max_bytes`. |

## personal_data

| Key | Default | Effect |
| --- | --- | --- |
| `uploader_key_resolver` | `null` | Service id implementing `PersonalData\UploaderKeyResolverInterface`: the stable key stored with each upload (`uploaded_by_key`) and matched by `StoredFilesPersonalData::exportUser()` / `eraseUser()`, so the GDPR export and erasure still find a person's files after their user identifier (e-mail) changes. Null = `UserIdUploaderKeyResolver`: `<real user class>:<id>` from a public `getId()` without arguments returning an integer, a non-empty string or a `Uuid` / `Ulid` (`sha256:<hex>` above 180 characters or for an anonymous class); no key otherwise (logged once per class). **The key must never change for a person**: changing the resolver after uploads needs a re-key ([UPGRADING](UPGRADING.md#to-120), step 4). Since 1.2.0. |
| `user_provider` | `security.user_providers` | Service id of the `UserProviderInterface` that `nowo:storage-kit:backfill-uploader-keys` loads users with, by stored identifier. A missing service only disables the command. Since 1.2.0. |

## doctrine

| Key | Default | Validation / effect |
| --- | --- | --- |
| `table_prefix` | `''` | `[A-Za-z0-9_]*`. Prefixes the tables and index names: `app_` gives `app_storage_kit_file`. |
| `entity_manager` | `null` | `\w+` or `null` (1.5). Null: the default manager (the 1.x default, unchanged for existing installs; 2.0 changes the default to a dedicated manager). A name: the bundle entities are mapped on that manager **only** (removed from the others, `auto_mapping` and a default metadata driver — DoctrineBundle 3.4 `auto_discover_entities`, fixed in Unreleased — included), every bundle read and write uses it, the bundle recovers it when closed and clears it at the end of every request, Messenger message and console command; the build fails when the manager is not declared under `doctrine.orm.entity_managers` or does not map the entities. **Recommended for new installs**, on the same connection as your default manager (see below). |

Tables: `storage_kit_profile`, `storage_kit_file`, `storage_kit_file_reference`, `storage_kit_sync` (see
[ARCHITECTURE](ARCHITECTURE.md#entities-and-tables)).

### Standard Symfony host

Keep `entity_manager: null`: the entities are attribute-mapped on your default manager. Uploads, references, the GDPR
erasure and the settings listener never flush it (DBAL statements); the admin pages and console commands do flush it
(profile save and test, moves, purge).

### DDD / hexagonal host (and new installs)

```yaml
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default: { connection: default }   # your mappings
            storage_kit: { connection: default }
nowo_storage_kit:
    doctrine:
        entity_manager: storage_kit
```

No bundle write flushes your manager then, admin and CLI included. Use the same connection, so uploads and
references join your transactions; reference the files by `uuid`, never with a Doctrine relation (see
[USAGE](USAGE.md#a-dedicated-entity-manager-15)).

## web_ui

Instance Storage page (`<path_prefix>`, test `<path_prefix>/test`) and Move page (`<path_prefix>/move`,
`<path_prefix>/move/{test|sync|cancel|switch|discard}`).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `enabled` | `true` | `false` = no instance or scope pages (no routes, no controllers); the upload endpoint, CLI and PHP API stay. The `storage` section then has no *Test connection* / *Usage and move* actions. Literal boolean. |
| `path_prefix` | `/admin/storage` | Absolute path. Protect it in `security.access_control` too. |
| `layout_template` | `null` | Layout the pages extend; null = `nowo_ui_kit.layouts.admin`. |
| `templates` | `{}` | Per-page overrides: `settings`, `move` (any other id is refused). See [TEMPLATES](TEMPLATES.md). |

## security

Access to the **instance** pages (scope pages use `scopes.access_checker`; uploads `upload.access_roles` and the file
context).

| Key | Default | Validation / effect |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Any of these roles opens the instance pages. Must contain a non-empty role unless `access_checker` is set or `allow_unauthenticated` is true. |
| `access_checker` | `null` | Service id implementing `Security\StorageAccessCheckerInterface` (replaces the role check). |
| `allow_unauthenticated` | `false` | Open the instance pages without login. **Refused unless `kernel.debug` is true** (demo only). |

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/storage, roles: ROLE_ADMIN }
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

## The `storage` Instance Settings section

Registered when `instance_profile` is null (`Settings\StorageSettingsSection`, priority −10, keys `storage.<field>`).
Requires `nowo-tech/instance-settings-bundle` ^1.3.1; provider-dependent rows use Instance Settings 1.3
`withVisibleWhen()` and the UiKit ^2.23 `visible-when` Stimulus peer, which the host must register
([INSTALLATION](INSTALLATION.md#visible-when-stimulus-peer)).

| Field | Type | Shown for | Required | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `provider` | choice | always | no | empty | `aws_s3` (Amazon S3) or `s3_compatible` (R2, B2, Wasabi, Scaleway, RustFS, MinIO…). **Empty = uploads off.** |
| `endpoint` | url | `s3_compatible` | yes | — | `http(s)://host[:port]` origin; SSRF guard unless `endpoints.guard_instance_profile: false` |
| `public_endpoint` | url | `s3_compatible` | no | — | `http(s)://host[:port]` origin (signed for, never fetched by the server) |
| `region` | string | both | no | `us-east-1` | `[a-z0-9-]` token, ≤ 32 |
| `bucket` | string | both | yes | — | S3 naming rules |
| `prefix` | string | both | no | — | safe characters, no `.` / `..` segment |
| `access_key` | string | both | yes | — | printable, no spaces |
| `secret` | secret | both | yes | — | Instance Settings secret: encrypted at rest, never rendered back, blank keeps the saved value |
| `path_style` | bool | `s3_compatible` | no | `false` | — |
| `access_key_csv` (1.1.0) | file, unmapped, not a setting | `aws_s3` | no | — | AWS access-key CSV that fills `access_key` and `secret` on submit; never stored ([USAGE](USAGE.md#import-from-aws-csv)) |

Only the fields visible for the **submitted** provider are validated and saved; the other provider's values are kept
(hidden, not cleared) but never used: with `aws_s3` the endpoint is derived from the region (virtual-hosted style) and
`endpoint` / `public_endpoint` / `path_style` are ignored.

Section notices: *No provider is selected: image uploads are off*; *incomplete* (a field is missing or invalid: uploads
stay off); *the last connection test failed*. Field names only, never values. With `web_ui.enabled`, the section page
has two extra actions: **Test connection** (POST + CSRF to `<web_ui.path_prefix>/test?from=settings`, which redirects
back to the section) and **Usage and move** (link to the instance Storage page).

## FormKit profile

The bucket form (`Form\StorageProfileType`, scope Storage page and Move target, with its unmapped `access_key_csv`
file row) uses the FormKit profile
`nowo_form_kit.profiles.storage_kit`, prepended by the bundle unless the host defines it (translation domain
`NowoStorageKitBundle`, no auto help / placeholder, no framework classes). Override it in `nowo_form_kit.profiles` to
restyle.

## Messenger

With a `messenger.default_bus`, *Start copying* on the Move page dispatches `Message\RunStorageSyncMessage`; the handler
runs `sync.slice_seconds` per message and re-dispatches. Route it to a maintenance transport:

```yaml
framework:
    messenger:
        routing:
            Nowo\StorageKitBundle\Message\RunStorageSyncMessage: async
```

Without a bus the handler is removed and the Move page tells the operator to run `nowo:storage-kit:sync <from> <to>`.

The handler carries `#[AsMessageHandler]` and the bundle registers it with an explicit `messenger.message_handler` tag
(its service is not autoconfigured, so it is registered once either way).

**Retry and failure policy.** A file that cannot be copied does not throw: the sync stops as `failed` with the file and
a scrubbed reason, and *Resume copying* (or the command) continues from that file. Only an infrastructure error (the
database or the lock store unreachable) throws, so the transport's `retry_strategy` applies (Symfony default: 3 retries,
delay doubling from 1 s). Every message is idempotent: one that finds its sync no longer `queued` (done, cancelled,
held by another runner, or claimed by the failed attempt) does nothing. A sync left `running` by a failed attempt is
taken over by `nowo:storage-kit:sync` once `sync.stale_seconds` (120 s) pass (or *Stop copying*, then *Resume
copying*). Messages that exhaust
their retries go to your `failure_transport`; `messenger:failed:retry` is safe. A dedicated transport keeps long slices
away from your other jobs:

```yaml
framework:
    messenger:
        transports:
            storage_kit:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 3, delay: 5000, multiplier: 2 }
        routing:
            Nowo\StorageKitBundle\Message\RunStorageSyncMessage: storage_kit
```

## Timeouts (REQ-RUNTIME-001)

| Layer | Value |
| --- | --- |
| DNS lookup of an endpoint (SSRF guard) | `endpoints.dns_timeout` (2 s) |
| One storage request | `endpoints.request_timeout` idle (30 s), `max_duration` 4× (120 s); no redirects for guarded buckets |
| Pre-signed URL | `download.url_ttl` (300 s); *Test connection* signs for 5 minutes |
| Sync slice (Messenger) | `sync.slice_seconds` (240 s); lock / takeover after `sync.stale_seconds` (120 s) |

Keep PHP `max_execution_time` and the web server write timeout above the storage request timeout for uploads and
streamed downloads.

## Container parameters and services

- Parameters: `nowo_storage_kit.doctrine.table_prefix`, `nowo_storage_kit.web_ui.enabled`,
  `nowo_storage_kit.upload.enabled`, `nowo_storage_kit.scopes.enabled`,
  `nowo_storage_kit.security.allow_unauthenticated`, `nowo_storage_kit.endpoints.allow_private`,
  `nowo_storage_kit.instance_settings` (whether the `storage` section is registered).
- Public services (PHP API): `Storage\FileStorage`, `Storage\StorageProbe`, `Profile\StorageProfiles`,
  `Profile\ConfigProfiles`, `Upload\ImageUploader`, `Upload\ImageProcessor`, `Upload\UploadEndpoint`,
  `Download\FileDelivery`, `Download\FileUrls`, `Download\StoredImageResolver`, `Reference\FileUris`,
  `Reference\FileReferences`, `Context\FileContexts`, `Lifecycle\FilePurger`, `Sync\StorageSyncer`, `Sync\StorageMove`,
  `PersonalData\StoredFilesPersonalData`, `Http\StorageCspSources`, `Security\EndpointGuard`, `Security\AccessGuard`,
  `Audit\AuditRecorder`, the three repositories and, when the section is registered, `Settings\InstanceStorageSettings`
  and `Settings\StorageSettingsSection`.
- `nowo_storage_kit.connection_factory` (alias of `S3StorageConnectionFactory`, interface
  `StorageConnectionFactoryInterface`): replace it in tests with an in-memory factory.
- Autoconfigured tags: `nowo_storage_kit.file_context` (`FileContextInterface`) and
  `nowo_storage_kit.reference_source` (`FileReferenceSourceInterface`).

## Fail-closed checks

The container refuses to compile when:

- `nowo-tech/doctrine-encrypt-bundle` is not registered (bucket secrets are encrypted at rest);
- `instance_profile` is null and Instance Settings is not registered (`InstanceSettingsPass`);
- `web_ui.enabled` is true, `allow_unauthenticated` is false and SecurityBundle is missing;
- the pages or the upload endpoint are on without CSRF (`framework.csrf_protection`), or the pages are on without form
  CSRF (`CsrfProtectionPass`);
- `security.allow_unauthenticated` or `endpoints.allow_private` is true outside `kernel.debug` (`DebugOnlySwitchesPass`);
- a security / feature switch is not a literal boolean;
- `instance_profile` names no `profiles` entry, `scopes.enabled` has no `access_checker`, a role list is empty, or
  `scopes.path_prefix` / `upload.path_prefix` is under `web_ui.path_prefix`.
