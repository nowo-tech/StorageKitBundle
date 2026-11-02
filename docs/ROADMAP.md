# Roadmap

## Table of contents

- [1.0 (this release)](#10-this-release)
- [1.1 (unreleased)](#11-unreleased)
- [Phase 2 — branded, editable e-mail templates](#phase-2--branded-editable-e-mail-templates)
- [Later candidates](#later-candidates)

## 1.0 (this release)

Runtime mailer on top of Instance Settings: `mailer` / `resend` sections, runtime DSN with fallback (also for
Messenger), DSN / sender validation with an SSRF host guard, redacted audit, test e-mail and Resend key check, sent-mail
log with retention and erasure, readiness (Twig, AuthKit adapter). Extracted from Symfony Beacon (kit #2, phase 1).

## 1.1 (unreleased)

- CSV export of the sent-mail log (streamed, capped, formula-injection safe through ControllerKit's
  `CsvStreamedResponse`) and server-side sortable log columns with UiKit 2.20 `Listing\SortRequest` — both requested
  by Symfony Beacon (its e-mail log, spec 188). No local sort helper: the request side is UiKit's, only the key → DQL
  column whitelist lives in `SentMailRepository`.
- Possible follow-up: an async export (Messenger + download link) for logs much larger than `web_ui.export.max_rows`.

## Phase 2 — branded, editable e-mail templates

Out of scope for 1.0 (Beacon kit #3, `src/Shared/EmailTemplate/**`):

- `EmailTemplate` entity (key + locale) with table prefix; template **definitions as tagged services** (key,
  placeholders, default subject / HTML / text) instead of a hard-coded catalog.
- HTML + text renderer with a branded layout and an HTML sanitizer; a `BrandProviderInterface` (name, colours, logo,
  base URI) — optionally fed by UiKit appearance.
- Admin list / edit / preview / reset (UiKit scaffolds, FormKit forms, editor widget), "send me a test" through the
  existing test e-mail guard and rate limit.
- Ready-made AuthKit notifiers (magic link, password reset, new device) that use the templates and
  `MailHeaders::redact()` for one-time links.
- The sent-mail log `tag` column already carries the template key.

## Later candidates

- Optional automatic purge (Scheduler message) instead of a cron line.
- Provider webhooks (bounces, complaints) updating the log status.
- `nowo-tech/runtime-env-bundle` DSN provider shipped as an optional integration.
