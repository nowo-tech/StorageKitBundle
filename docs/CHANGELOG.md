# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.1] - 2026-11-01

CSV export: body batches by byte budget, max_rows capped at 100000, controller-kit ^2.3.1 (formula guard after separators); demo install race fixed See [UPGRADING](UPGRADING.md#to-111).

### Security

- Formula injection through an embedded list separator (CA delta 1.1.0 D1, Low): `nowo-tech/controller-kit-bundle`
  raised to `^2.3.1`, whose `CsvSafeWriter` also puts a space before a formula trigger that follows `;`, `,`, tab,
  `|` or a line break inside a cell (Excel in `;` list-separator locales split `Hi;=cmd|…` into an evaluated cell).
  The CSV and JSON responses also send `X-Content-Type-Options: nosniff`. Regression test reads the export with `;`.
- Memory of the export with bodies (CA delta 1.1.0 D2, Low, opt-in): bodies were loaded 100 rows per query, up to
  ~200 MiB with 1 MiB text and HTML parts. They are now loaded in chunks of about `web_ui.export.body_batch_bytes`
  (new, default 8 MiB, 1 MiB … 64 MiB), sized first with a `LENGTH()` query (`SentMailRepository::bodySizes()`); a
  single row over the budget is fetched alone. New `SentMailCsvExporter::chunkBySize()` / `BODY_BATCH_BYTES`.
- `web_ui.export.max_rows` is capped at 100 000 (was 1 000 000; `SentMailCsvExporter::MAX_ROWS_LIMIT`): `pdo_mysql`
  buffers the whole result in the client, so memory grows with the row cap (CA delta 1.1.0 I-D2).
- `docs/SECURITY.md` no longer overstates the CSV guarantee (best effort for common spreadsheets), and documents the
  MySQL buffered-result cost and the partial file on a mid-stream error.

### Changed

- `nowo-tech/ui-kit-bundle` raised to `^2.21` (bundle and demo).
- Root `docker-compose.yml`: optional MySQL 8.4 `db` service (`db` profile, tmpfs data); the root image gets
  `pdo_mysql`. `make test-with-db` runs the Integration suite on SQLite, then `tests/Integration/Log` on that service
  with `STORAGE_KIT_TEST_DATABASE_URL` exported, like the "Log on …" CI jobs; `make test-coverage-with-db` does the same
  with coverage (REQ-TEST-005).
- `specs/001-baseline`: `SentMailCsvExporter` mapped to the new FR-LOG-006 (70 files, REQ-SPECKIT-003).
- Docs: README coverage figures refreshed; the duplicate "To 1.0.0" UPGRADING section and the repeated 1.0.0 intro
  line of this changelog removed.

### Fixed

- Demo `make up` (`demo/symfony8/Makefile`) waits until the entrypoint's first `composer install` has finished (the
  server answers) before it runs its own install. The two installs raced on the shared `vendor/` and corrupted the
  downloaded archives, which turned the Playwright e2e job of the v1.1.0 tag red (REQ-DEMO-013).
- Demo lock: the bundle is pinned as `dev-main` (path repository `versions` option) instead of the feature branch
  it was last refreshed from.

## [1.1.0] - 2026-11-01

Sent-mail log CSV export (formula-injection safe, filters applied, row cap) and server-side sortable columns (UiKit 2.20 SortRequest). See [UPGRADING](UPGRADING.md#to-110).

### Added

- **CSV export of the sent-mail log** (`GET <path_prefix>/log/export`, route `nowo_storage_kit_log_export`, "Export CSV"
  action on the list): current filters and sort; columns date, status, tag, from, to, cc, bcc, subject, error
  (scrubbed), reference; translated header row. Streamed through `nowo-tech/controller-kit-bundle`
  `Http\CsvStreamedResponse` / `CsvSafeWriter` (formula-injection safe, `no-store`, attachment), capped at
  `web_ui.export.max_rows` (10 000) with a closing notice row and `X-Mail-Kit-Export-Truncated`. Same access as the
  log plus optional `web_ui.export.access_roles`; bodies only with `log.store_bodies` + `web_ui.export.include_bodies`
  + `?bodies=1`. New config `web_ui.export.{enabled, max_rows, access_roles, include_bodies}`; `SentMailCsvExporter`,
  `AccessGuard::isExportGranted()`.
- **Sortable log columns** (date, status, subject, tag, transport) on the server with UiKit 2.20 `Listing\SortRequest`
  and `Column` `sortable` / `sortFirst`: whitelist (`SentMailRepository::SORT_FIELDS`), unknown keys fall back to
  newest first, ties broken by date and id; the sort is kept by pagination, the filter form (hidden `sort` / `dir`)
  and the export link. `SentMailRepository::findFiltered()` takes an optional sort key and direction (refuses keys
  outside the whitelist); new `iterateFiltered()` / `findBodies()`.

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.20` (was `^2.17.3`) and `nowo-tech/controller-kit-bundle` `^2.3` (new).

## [1.0.0] - 2026-10-31

First release: runtime-configured mailer on InstanceSettings (encrypted DSN), DSN/sender validation with SSRF-guarded provider endpoints, test e-mail, Resend check, privacy-first sent-mail log with retention and right-to-erasure, AuthKit readiness adapter. See [UPGRADING](UPGRADING.md#to-100-initial-release).

### Added

- **Settings sections** contributed to Instance Settings: `mailer` (DSN as encrypted secret, sender address and name,
  Reply-To, test recipient) and, with `resend.enabled`, `resend` (switch, encrypted API key, sender).
- **Runtime DSN**: `RuntimeTransport` decorates `mailer.transports` (framework mailer and Messenger
  `SendEmailMessage` handler): the DSN is resolved on every send from a `MailerDsnProviderInterface` (Instance
  Settings by default, Resend key first when active), the framework transport (`MAILER_DSN`) is the fallback, named
  transports (`X-Transport`) are kept, the built transport is cached per DSN and reset on `kernel.reset` and
  `SettingsChangedEvent` (worker safe); a refused DSN fails the send.
- **`MailerInterface` decorator** (`StorageKitMailer`): runtime From / Reply-To when missing, "queued" log rows when the
  mailer dispatches to Messenger.
- **DSN validation** (`MailerDsnValidator`, `#[MailerDsn]`): scheme allow-list (SMTP + provider bridges; `sendmail`,
  `native`, `null` always refused), parse and build check, SSRF host guard (`DsnHostGuard`, OutboundUrlGuard rules,
  bounded DNS resolution, `allowed_hosts`, `allow_private_hosts`).
- **Sender rules** (`SenderAddress`, `#[DeliverableSender]`): public-domain senders only.
- **Redacted audit**: `MailerDsnAudit` (`scheme://host[:port]`), `Mail settings changed.` notices, `ErrorScrubber`
  for stored and displayed transport errors.
- **Test e-mail**: "Send a test e-mail" action on the mailer section (CSRF, StorageKit + section access, per-user rate
  limit), `TestMailSender`, `nowo:storage-kit:send-test [to] [--resend]`.
- **Resend**: "Check the API key" (`ResendApiChecker`, deadline, no redirects, sending-only keys) and a Resend test
  e-mail.
- **Sent-mail log**: `SentMail` / `SentMailBody` entities (`doctrine.table_prefix`), DBAL recorder (best effort,
  never the host unit of work), bodies only with `log.store_bodies` (redacted values masked), tags and redaction
  headers (`MailHeaders`), UiKit list (filters, pagination) and detail pages, sandboxed HTML body,
  `nowo:storage-kit:log:purge` (retention, keyset batches) and `nowo:storage-kit:log:forget <email>` (erasure).
- **Readiness**: `OutboundMailReadiness::isOutboundMailReady()` / `status()`, Twig `nowo_storage_kit_outbound_mail_ready()`
  and `nowo_storage_kit_status()`, AuthKit `OutboundMailReadyCheckerInterface` adapter prepended automatically.
- **Admin UI**: opt-in `nowo_storage_kit` route loader (REQ-SF-004 rule 7), `security.access_roles` /
  `access_checker` / `allow_unauthenticated`, FormKit profile `storage_kit`, UiKit page contract (REQ-UI-003),
  translations in en, es, fr, de, it, pt, nl.
- **Fail-closed wiring**: compile errors without Instance Settings, the framework mailer, form CSRF, roles, or with
  `allow_unauthenticated` outside `kernel.debug`.
- Demo `demo/symfony8` (Symfony 8.1, FrankenPHP worker, MySQL, Mailpit) with Playwright e2e and README screenshots;
  Flex recipe; CI on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100% coverage gate, plus the log tests on MySQL, MariaDB
  and PostgreSQL.

### Security (independent audit, Appendix CA)

- **M1** DSN query options are part of the SSRF guard: `region` only from a per-bridge allow-list, no host-shaping
  characters in any option, and the built transport's real endpoint host must be a provider host or pass the guard.
- **M1 / L3 / I3** Transport failures become `DeliveryFailedException` (short scrubbed message + reference, no provider
  response body, no `previous`): safe in flashes, the log, the CLI and the Messenger failure transport. The scrubber
  masks the DSN credentials in use and provider key formats (`SG.`, `xkeysib-`, `key-`, `username "…"`, UUID tokens).
- **L1** `isOutboundMailReady()` / `status()` validate without DNS (no child process per anonymous login page).
- **L2** Normalised `recipient_index` column for search and erasure (no JSON matching: works on SQLite, MySQL / MariaDB
  and PostgreSQL, also for `/` and IDN addresses); `forgetAddress()` also deletes rows sent by the address and masks
  it in subjects and bodies; the CLI accepts internationalised addresses.
- **I1** The Resend key check shares the per-user rate limit. **I2** Single-label DSN hosts are refused unless
  allow-listed. **I4** Redacted values are also masked URL-encoded and base64. **I7** `#[SensitiveParameter]` on the
  validators.
- Requires `nowo-tech/instance-settings-bundle` ^1.1 (secret-field errors rendered on the section page).

### Security (re-verification follow-ups)

- **N2** SES (`ses+api`, `ses+https`) and Scaleway (`scaleway+api`) DSNs validate again: the endpoint of these bridges is
  read per bridge (`accessKey@region` → `email.<region>.amazonaws.com`, `endpoint@project`), `scaleway.com` is a provider
  host; CI installs the amazon and scaleway bridges and sends through a mocked client.
- **N1** An unreachable API endpoint (connect / TLS / DNS failure) gives the scrubbed `DeliveryFailedException`
  ("could not be reached") instead of the raw HttpClient exception.
- **R1** The recipient index separates addresses with a control character no address can contain (forgetting
  `bob@x.com` no longer touches `a|bob@x.com`). **R2** Addresses are NFC-normalised and indexed in Unicode and punycode
  forms (erasing `user@xn--bcher-kva.de` also erases `user@bücher.de`). **R3** documented.
- `verify_peer=0` / `verify_peer_name=0` are refused unless `dsn.allow_insecure_tls: true` (dev only, literal
  boolean); the SES region pattern is anchored at the end of the string; a backslash-escaping bug that refused every
  option value containing `s` is fixed.
