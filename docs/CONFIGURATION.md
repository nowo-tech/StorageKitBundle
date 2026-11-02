# Configuration

## Table of contents

- [Full reference](#full-reference)
- [mailer](#mailer)
- [dsn](#dsn)
- [sender](#sender)
- [resend](#resend)
- [test_email](#test_email)
- [log](#log)
- [doctrine](#doctrine)
- [web_ui](#web_ui)
  - [Layout and CSS stack (UiKit)](#layout-and-css-stack-uikit)
- [security](#security)
- [FormKit profile](#formkit-profile)
- [Instance Settings](#instance-settings)
- [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [Profiles](#profiles)
- [Container parameters and services](#container-parameters-and-services)
- [Fail-closed checks](#fail-closed-checks)

## Full reference

```yaml
# config/packages/nowo_storage_kit.yaml (defaults shown)
nowo_storage_kit:
    mailer:
        decorate: true                 # decorate mailer.mailer + mailer.transports (runtime DSN on every send)
        dsn_provider: null             # service id implementing MailerDsnProviderInterface (null = Instance Settings)
        fallback_counts_as_ready: false # MAILER_DSN counts for isOutboundMailReady() when nothing is stored
    dsn:
        allowed_schemes: [smtp, smtps, mailgun, mailgun+api, …, scaleway+smtp]   # sendmail / native / null refused
        allowed_hosts: []              # hosts accepted even when private ("mailpit", "relay.corp.example")
        allow_private_hosts: false     # SSRF guard off for private / local hosts (metadata stays refused)
        allow_insecure_tls: false      # DEV only: accept verify_peer=0 / verify_peer_name=0
        resolve_dns: true              # resolve host names; refuse private answers
        dns_timeout: 2.0               # seconds per lookup (0.1 … 10)
    sender:
        default_from: null             # default of mailer.from
        default_from_name: null        # default of mailer.from_name
        require_public_domain: true    # refuse .local / .localhost / single-label senders
    resend:
        enabled: false                 # adds the "resend" section (needs symfony/resend-mailer to send)
        api_timeout: 10.0              # seconds, key check (1 … 60)
    test_email:
        rate_limit: 5                  # per user and window (1 … 100)
        rate_interval: '10 minutes'
    log:
        enabled: true
        store_bodies: false            # personal data: off by default
        retention_days: 30             # nowo:storage-kit:log:purge default (1 … 3650)
        purge_batch_size: 1000         # rows per DELETE (1 … 100000)
    doctrine:
        table_prefix: ''               # 'app_' => app_storage_kit_sent_mail
        entity_manager: null           # ORM entity manager name (null = default)
    web_ui:
        enabled: true                  # false = no routes / controller / settings actions
        path_prefix: /admin/mail       # protect it with security.access_control
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        templates: {}                  # log_index, log_show
        page_size: 25                  # rows per log page (5 … 200)
        export:
            enabled: true              # GET <path_prefix>/log/export (CSV of the filtered, sorted log)
            max_rows: 10000            # rows per export (1 … 100 000); longer results end with a notice row
            access_roles: []           # extra roles the export needs (any of them); [] = the log access is enough
            include_bodies: false      # offer "Export CSV with bodies" (needs log.store_bodies: true)
            body_batch_bytes: 8388608  # approximate body bytes loaded per query by the export with bodies (1 MiB … 64 MiB)
    security:
        access_roles: [ROLE_ADMIN]     # any of them; must not be empty (unless access_checker / allow_unauthenticated)
        access_checker: null           # service id implementing StorageKitAccessCheckerInterface
        allow_unauthenticated: false   # DEV / DEMO only; refused unless kernel.debug is true
```

Unknown keys are rejected. Switches (`mailer.decorate`, `dsn.allow_private_hosts`, `dsn.allow_insecure_tls`, `resend.enabled`, `log.enabled`, `log.store_bodies`, `web_ui.enabled`, `web_ui.export.enabled`, `web_ui.export.include_bodies`, `security.allow_unauthenticated`) must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built.

## mailer

| Key | Default | Description |
| --- | --- | --- |
| `decorate` | `true` | Registers `nowo_storage_kit.mailer` (decorates `mailer.mailer`, the `MailerInterface`) and `RuntimeTransport` as decorator of `mailer.transports`, so the framework mailer **and** the Messenger `SendEmailMessage` handler use the runtime DSN. With `false`, the framework mailer is untouched; `RuntimeTransport` (fallback = `mailer.transports`), the test e-mail, readiness and the log still work for code that uses them directly. |
| `dsn_provider` | `null` | Service id of a `MailerDsnProviderInterface`; the default reads the `mailer` / `resend` settings. |
| `fallback_counts_as_ready` | `false` | `isOutboundMailReady()` is false while nothing is stored, because `MAILER_DSN` cannot be inspected through the framework transports. Set it to `true` when your `MAILER_DSN` is a real transport. |

## dsn

Applies to the DSNs stored in the settings and returned by a custom provider; the framework `MAILER_DSN` fallback is trusted ops configuration and not validated.

| Key | Default | Description |
| --- | --- | --- |
| `allowed_schemes` | SMTP + provider bridges | `smtp`, `smtps`, `mailgun(+api/+https/+smtp)`, `postmark(+api/+smtp)`, `sendgrid(+api/+smtp)`, `ses(+api/+https/+smtp)`, `brevo(+api/+smtp)`, `resend(+api/+smtp)`, `mailjet(+api/+smtp)`, `mailpace(+api/+smtp)`, `scaleway(+api/+smtp)`. `sendmail`, `sendmail+smtp`, `native` and `null` are refused at compile time. |
| `allowed_hosts` | `[]` | Exact host names (case-insensitive) accepted without the private / DNS checks. Single-label names (`mailpit`, `relay`) are refused unless listed here. |
| `allow_private_hosts` | `false` | Accept private, loopback, link-local and reserved hosts. Cloud-metadata hosts (`169.254.169.254`, `metadata.google.internal`, `100.100.100.200`, `fd00:ec2::254`…) stay refused. |
| `allow_insecure_tls` | `false` | DEV only. Accept `verify_peer=0` / `verify_peer_name=0` (TLS certificate checks off) in stored DSNs; otherwise `dsn.insecure_tls`. Literal boolean. |
| `resolve_dns` | `true` | Resolve host names (A / AAAA, `gethostbynamel` fallback) and refuse a name that does not resolve or resolves to any private address. The lookup runs in a bounded child process (`dns_timeout`). |
| `dns_timeout` | `2.0` | Seconds per lookup. |

Validation (query options and the built transport's endpoint included) runs on save / import and once per DSN per worker before sending (memoized with the transport). Readiness checks skip DNS. DNS rebinding after validation is a residual risk: see [SECURITY](SECURITY.md).

## sender

| Key | Default | Description |
| --- | --- | --- |
| `default_from` | `null` | Default of `mailer.from` (validated as an e-mail address). |
| `default_from_name` | `null` | Default of `mailer.from_name`. |
| `require_public_domain` | `true` | Adds `#[DeliverableSender]` to `mailer.from` / `resend.from` and checks the test e-mail sender. |

## resend

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Registers the `resend` section, its routes (`/actions/resend/*`) and `ResendApiChecker`. Sending needs `symfony/resend-mailer`. |
| `api_timeout` | `10.0` | Connect + total deadline of the key check (`timeout` and `max_duration` of HttpClient). |

## test_email

| Key | Default | Description |
| --- | --- | --- |
| `rate_limit` | `5` | Test e-mails and Resend key checks per user (`getUserIdentifier()`, else the client address) per window. Sliding window over `cache.app` (limiter `nowo_storage_kit.test_email.limiter`). |
| `rate_interval` | `'10 minutes'` | Relative time (`strtotime` syntax). |

## log

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Sent-mail log writes, log pages and the purge / forget commands. |
| `store_bodies` | `false` | Store the HTML / text bodies (≤ 1 MiB each, redacted values masked) and expose `GET …/log/{id}/body`. |
| `retention_days` | `30` | Default age of `nowo:storage-kit:log:purge`. |
| `purge_batch_size` | `1000` | Rows per keyset batch. |

## doctrine

| Key | Default | Description |
| --- | --- | --- |
| `table_prefix` | `''` | Prefix of the bundle tables **and** their index names (REQ-ORM-002), applied by `TablePrefixListener` on `loadClassMetadata` to the bundle entities only. `app_` gives `app_storage_kit_sent_mail`, `app_storage_kit_sent_mail_body`, `app_idx_storage_kit_sent_mail_queued`, `app_idx_storage_kit_sent_mail_status`. The recorder (DBAL) uses the same names. |
| `entity_manager` | `null` | Entity manager of the log (resolved per call). |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Controller, access listener, routes of the `nowo_storage_kit` loader and the "send a test e-mail" / Resend actions on the settings sections. With `false` the bundle needs neither SecurityBundle nor form CSRF. |
| `path_prefix` | `/admin/mail` | URL prefix of every admin route. |
| `layout_template` | `null` | Layout the pages extend; `null` uses `nowo_ui_kit.layouts.admin` (REQ-UI-003). It must render the UiKit canonical blocks. |
| `templates` | `{}` | Per-page overrides: `log_index`, `log_show` (see [TEMPLATES](TEMPLATES.md)). |
| `page_size` | `25` | Rows per log page (`per_page` query values 10, 25, 50, 100 and this one are accepted). |
| `export.enabled` | `true` | Route `nowo_storage_kit_log_export` (`GET <path_prefix>/log/export`) and the "Export CSV" action of the log list. Needs `log.enabled`. |
| `export.max_rows` | `10000` | Rows of one export at most (1 … 100 000). On MySQL / MariaDB `pdo_mysql` buffers the whole result in the client before the first row is written (a few KiB per row), so memory grows with this value. When more rows match, the file ends with a translated notice row ("Export truncated: the first N of M rows…"), the `X-Mail-Kit-Export-Truncated` response header is `1` and the list action reads "Export CSV (first N rows)". |
| `export.access_roles` | `[]` | Roles the export needs **on top of** the log access (`security.*`), any of them. Empty: whoever may open the log may export it. With roles set, anonymous access (`allow_unauthenticated`) cannot export. |
| `export.include_bodies` | `false` | Adds "Export CSV with bodies" (`?bodies=1`: text and HTML body columns). Refused at compile time unless `log.store_bodies: true`; without it `?bodies=1` is ignored. Each stored body part may be 1 MiB: the bodies are loaded in chunks of `export.body_batch_bytes`. |
| `export.body_batch_bytes` | `8388608` | Approximate body bytes (text + HTML length) loaded with one query by the export with bodies (1 MiB … 64 MiB); a row over it is loaded alone. The export then needs about this much memory plus one row on top of the request. |

Example: let support staff read the log but keep the CSV for the DPO role, capped at 5 000 rows.

```yaml
nowo_storage_kit:
    security:
        access_roles: [ROLE_SUPPORT, ROLE_ADMIN]
    web_ui:
        export:
            max_rows: 5000
            access_roles: [ROLE_DPO]
```

### Layout and CSS stack (UiKit)

The pages are UiKit scaffolds: the CSS stack, icon set and theme are configured **once** for every Nowo bundle in `nowo_ui_kit` (REQ-UI-003), not per bundle:

```yaml
nowo_ui_kit:
    css_framework: bootstrap5          # bootstrap5 | tailwind | foundation | custom | none …
    layouts:
        admin: 'admin/layout.html.twig'
```

Bundle pages add no CSS / JS of their own.

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Any of these roles opens the UI. An empty list is refused at compile time unless `access_checker` is set or `allow_unauthenticated` is true. |
| `access_checker` | `null` | Service id implementing `StorageKitAccessCheckerInterface` (`canAccess(?object $user): bool`). |
| `allow_unauthenticated` | `false` | **DEV / DEMO only.** Opens the UI without login; refused unless `kernel.debug` is true. |

The settings actions additionally require the Instance Settings access of their section (`Nowo\InstanceSettingsBundle\Security\AccessGuard::isGranted('mailer' | 'resend')`: its `security.access_roles` / `area_roles` / checker and `allow_unauthenticated`). Protect the prefix with a firewall **and** `access_control`:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/mail, roles: ROLE_ADMIN }
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

## FormKit profile

The forms (log filters, CSRF-only actions) are FormKit types bound to the profile **`nowo_form_kit.profiles.storage_kit`** (REQ-TWIG-005 rule 11), prepended by the bundle with: translation domain `NowoStorageKitBundle`, `auto_help: false`, `auto_placeholder: false`, no framework classes (the UiKit scaffolds paint the fields). Define the profile yourself to change it; the bundle then prepends nothing:

```yaml
nowo_form_kit:
    profiles:
        storage_kit:
            translation_domain: NowoStorageKitBundle
            defaults: { attr: { class: 'form-control' } }
```

## Instance Settings

StorageKit requires `NowoInstanceSettingsBundle` (and its DoctrineEncrypt key for the secret fields). The sections are tagged `nowo_instance_settings.section` with priorities 50 (`mailer`) and 49 (`resend`); restrict them with `nowo_instance_settings.security.area_roles: { mailer: [ROLE_SUPER_ADMIN], resend: [ROLE_SUPER_ADMIN] }`. Sealed exports carry the DSN and the API key only with "include secrets".

## Timeouts (REQ-RUNTIME-001)

| Layer | Default | Where |
| --- | --- | --- |
| DNS lookup of a DSN host | 2 s | `dsn.dns_timeout` |
| Resend key check | 10 s connect + total, no redirect | `resend.api_timeout` |
| SMTP / provider delivery | Symfony mailer defaults (`timeout` DSN option, default `default_socket_timeout`) | add `?timeout=10` style options to the DSN when needed |
| PHP `max_execution_time` | must be **greater** than the above | php.ini / FrankenPHP `php_ini` |
| Proxy / Caddy write timeout | must be **greater** than PHP | demo `Caddyfile` |

The test e-mail and the key check run in the request: keep the operation deadlines below `max_execution_time`. Bulk mail belongs on Messenger (the worker resolves the DSN).

## Profiles

REQ-CFG-001 does not apply: the bundle has one configuration tree. Variants (another DSN source) are a `mailer.dsn_provider` service, not named profiles.

## Container parameters and services

Parameters: `nowo_storage_kit.doctrine.table_prefix`, `.web_ui.enabled`, `.web_ui.path_prefix`, `.log.enabled`, `.log.retention_days`, `.resend.enabled`, `.security.allow_unauthenticated`.

Public services: `Nowo\StorageKitBundle\Mailer\RuntimeTransport`, `Readiness\OutboundMailReadiness`, `Dsn\MailerDsnValidator`, `TestMail\TestMailSender`, `Log\SentMailRecorder` and `Log\SentMailRepository` (log on), `Resend\ResendApiChecker` (Resend on), `nowo_storage_kit.outbound_mail_ready_checker` (AuthKit installed). Interfaces: `Dsn\MailerDsnProviderInterface`, `Security\StorageKitAccessCheckerInterface`.

## Fail-closed checks

The container refuses to compile when:

- `NowoInstanceSettingsBundle` is not registered, or the framework mailer is off (`mailer.transports` missing);
- the admin UI is on without SecurityBundle (unless `allow_unauthenticated`), or without form CSRF (`framework.csrf_protection`, `framework.form.csrf_protection`);
- `security.access_roles` is empty without `access_checker` / `allow_unauthenticated`;
- `security.allow_unauthenticated: true` outside `kernel.debug`;
- `dsn.allowed_schemes` contains `sendmail`, `sendmail+smtp`, `native` or `null`;
- a switch is an env placeholder, a prefix / path / interval / template id is invalid.
