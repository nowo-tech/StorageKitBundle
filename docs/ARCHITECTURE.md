# Architecture

## Table of contents

- [Overview](#overview)
- [Send flow](#send-flow)
- [Admin flow](#admin-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Worker safety](#worker-safety)
- [Extension points](#extension-points)

## Overview

```
Instance Settings ◄── MailerSettingsSection / ResendSettingsSection (fields, #[MailerDsn], #[DeliverableSender], actions)
      │ getSecret('mailer.dsn'), getString('mailer.from') …
      ▼
MailSettings ──► SettingsMailerDsnProvider (MailerDsnProviderInterface) ──► RuntimeTransport ◄── StorageKitMailer
      │                                                                     (decorates          (decorates
      └──► SenderPolicy (From / Reply-To)                                    mailer.transports)  mailer.mailer)
                                                                                 │
               MailerDsnValidator (schemes, DsnHostGuard → OutboundUrlGuard rules, factory) ◄┘
                                                                                 │ sent / failed
                                                                                 ▼
                                    SentMailRecorder (DBAL) ──► <prefix>storage_kit_sent_mail(_body) ◄── SentMailRepository (ORM)
OutboundMailReadiness ──► Twig functions, AuthKit OutboundMailReadyCheckerInterface adapter
```

## Send flow

1. Application code calls `MailerInterface::send()` → `StorageKitMailer` applies the runtime sender and (with a message bus)
   records a `queued` row whose id travels in `X-Mail-Kit-Log-Id`.
2. The framework `Mailer` sends now or dispatches `SendEmailMessage`; both end in `mailer.transports` =
   `RuntimeTransport`.
3. `RuntimeTransport` asks the provider for a DSN: none (or an `X-Transport` header) → framework transport; otherwise it
   reuses the transport built for that DSN or validates the DSN and builds one with `mailer.transport_factory`.
4. Internal headers are stripped, the message is sent, and the outcome (`sent` with provider id and size, or `failed`
   with a scrubbed error) updates the queued row or inserts one.

## Admin flow

1. Settings: Instance Settings renders the `mailer` / `resend` sections; values are validated by StorageKit's constraints on
   save / import; `SettingsChangedEvent` → `SettingsChangedListener` resets the transport and audits (redacted).
2. Actions: the section's UiKit `Action`s post a FormKit CSRF-only form to `StorageKitController` (`/actions/*`), which
   checks `AccessGuard::isSectionGranted()` (StorageKit checker + Instance Settings section access), the token and the rate
   limit, then runs `TestMailSender` / `ResendApiChecker` and redirects back with a flash.
3. Log: `StorageKitAccessListener` guards the prefix; `StorageKitController::index()` / `show()` build UiKit view models with
   `AdminPages` from `SentMailRepository`.

## Components

| Component | Role |
| --- | --- |
| `Settings\MailerSettingsSection`, `ResendSettingsSection`, `MailSettings` | Settings contract and typed view |
| `Dsn\MailerDsnProviderInterface`, `SettingsMailerDsnProvider`, `RuntimeDsn`, `DsnSource` | Where the DSN comes from |
| `Dsn\MailerDsnValidator`, `DsnHostGuard`, `MailerDsnAudit` | Validation, SSRF guard, redaction |
| `Validator\MailerDsn`, `DeliverableSender` (+ validators) | Field constraints |
| `Sender\SenderAddress`, `SenderPolicy` | Sender rules and defaults |
| `Mailer\RuntimeTransport`, `StorageKitMailer`, `MailHeaders` | Runtime delivery and internal headers |
| `Log\SentMailRecorder`, `SentMailRepository`, `SentMailFilters`, `SentMailStatus`, `ErrorScrubber`, `Entity\*` | Sent-mail log |
| `TestMail\TestMailSender`, `TestMailGuard`, `TestMailResult`, `TestMailOutcome` | Test e-mail |
| `Resend\ResendApiChecker`, `ResendCheckResult` | Resend key check |
| `Readiness\OutboundMailReadiness`, `MailStatus`, `Integration\AuthKit\StorageKitOutboundMailReadyChecker`, `Twig\StorageKitTwigExtension` | Readiness |
| `Controller\StorageKitController`, `Routing\StorageKitRouteLoader`, `Ui\AdminPages`, `StorageKitPages`, `SettingsActions`, `Form\SentMailFilterType` | Admin UI |
| `Security\*`, `EventListener\StorageKitAccessListener` | Access |
| `Command\*` | CLI (purge, forget, send-test) |
| `DependencyInjection\*`, `Doctrine\TablePrefixListener` | Wiring, table prefix |

## Container wiring

Services are registered explicitly by `NowoStorageKitExtension` (no autoconfiguration of bundle classes). `mailer.decorate`
adds the two decorators; `MailerIntegrationPass` (before optimization) checks Instance Settings and the framework mailer
and tells `StorageKitMailer` whether the mailer has a bus. `CsrfProtectionPass` and `UnauthenticatedAccessPass` fail closed
for the admin UI; `TwigPathsPass` registers `@NowoStorageKitBundle`. The extension prepends the Doctrine mapping, the
FormKit profile `storage_kit` and, with AuthKit, `nowo_auth_kit.outbound_mail_ready_checker`.

## Worker safety

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): one cached transport keyed by DSN, reset on `kernel.reset`
and settings changes; every other service is stateless and reads the settings per call.

## Extension points

- `nowo_storage_kit.mailer.dsn_provider` — your `MailerDsnProviderInterface`.
- `nowo_storage_kit.security.access_checker` — your `StorageKitAccessCheckerInterface`.
- `MailHeaders::tag()` / `redact()` — log category and body masking per message.
- `web_ui.templates.*` — page overrides (extend + one block).
- Instance Settings `area_roles` for the `mailer` / `resend` sections.
