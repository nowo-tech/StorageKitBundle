# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **StorageKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram--external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## User stories

The sections below state **behavior**; this subsection states **intent** in backlog-friendly form.

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** to install and enable this package **so that** I can rely on documented services, configuration, and extension points. |
| US-02 | **As an** integrator, **I want** stable configuration keys and defaults **so that** upgrades remain predictable (see [`CONFIGURATION.md`](CONFIGURATION.md)). |
| US-03 | **As an** integrator, **I want** clear usage guidance **so that** I can integrate features without reverse-engineering internals (see [`USAGE.md`](USAGE.md)). |
| US-04 | **As a** maintainer, **I want** behavior changes covered by automated tests **so that** regressions are caught in CI. |
| US-05 | **As a** contributor, **I want** `REQ-*` anchors on scripted flows **so that** PRs and issues cite the same identifiers as this document. |
| US-06 | **As an** operator, **I want** to change the mailer DSN and sender in an admin page **so that** the next message uses them without a deploy. |
| US-07 | **As an** operator, **I want** a "send a test e-mail" button and a Resend key check that report the provider's answer **so that** I can verify the configuration immediately. |
| US-08 | **As a** security reviewer, **I want** stored DSNs restricted to safe schemes and public hosts, secrets encrypted and never rendered, logged or exported in clear **so that** the admin form cannot be used for SSRF, host commands or credential leaks. |
| US-09 | **As a** support engineer, **I want** a sent-mail log with status, recipients and errors (bodies only when enabled) and a retention / erasure command **so that** I can answer "was it sent?" within privacy rules. |
| US-10 | **As an** AuthKit integrator, **I want** magic-login and password-reset to appear only when mail can be delivered **so that** users are never sent to a dead end. |

**Out of scope for these stories:** guarantees outside the stated public API and outside dependency limits (PHP, Symfony, third-party libraries).

---

## Bundle functional scope

**Goal:** a runtime-configurable mailer on top of Instance Settings: the DSN and sender come from the settings on every send (framework transport as fallback), validated and audited without secrets, with test e-mail, Resend check, sent-mail log and readiness, worker-safe.

**In scope**

- Settings sections `mailer` / `resend` (fields, constraints, actions) contributed to Instance Settings.
- `RuntimeTransport` (decorates `mailer.transports`) and `StorageKitMailer` (decorates `mailer.mailer`): runtime DSN, fallback, named transports, Messenger, sender policy, internal headers, log outcome; `MailerDsnProviderInterface`.
- DSN validation (`MailerDsnValidator`, `DsnHostGuard`, `#[MailerDsn]`), sender rules (`SenderAddress`, `#[DeliverableSender]`), `MailerDsnAudit`, `ErrorScrubber`.
- Test e-mail (`TestMailSender`, `TestMailGuard`, actions, CLI), Resend key check (`ResendApiChecker`).
- Sent-mail log (`SentMail`, `SentMailBody`, `SentMailRecorder`, `SentMailRepository`, filters, purge / forget commands, UiKit list and show pages, table prefix).
- Readiness (`OutboundMailReadiness`, `MailStatus`, Twig functions, AuthKit adapter).
- Admin UI (opt-in route loader, access checker, CSRF, FormKit forms) and fail-closed container checks.
- Documented integration (see root `README.md` and `docs/`), configuration and runtime behaviour in [`CONFIGURATION.md`](CONFIGURATION.md) and [`USAGE.md`](USAGE.md), consumer-facing notes in [`CHANGELOG.md`](CHANGELOG.md) / [`UPGRADING.md`](UPGRADING.md).

**Explicit non-goals**

- Branded, editable e-mail templates and ready-made AuthKit notifiers (phase 2, [ROADMAP](ROADMAP.md)).
- Storing settings itself (Instance Settings), SMS (SmsKit), bulk / marketing mail, bounce or webhook processing.
- Behavior not documented here or in linked integrator docs.
- **`demo/`** trees: illustrative unless a path is explicitly published as stable API in this document.

**Demos** (if present): examples only; not part of the Packagist contract unless services or contracts are explicitly documented as stable.

---

## Validating the functional spec

- Run **`composer qa`** and/or **`make qa`** / **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md) (Docker-based flows may apply).
- Run **PHPUnit** and **PHPStan** in CI and locally for code changes.
- New or changed behavior should add or adjust **tests** under `tests/` (or the repo’s documented test layout) rather than relying on prose alone.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-MAKE-002 | `Makefile` `release-check` | Sequential pre-release chain (co-author / open PRs / raw forms / Twig Extra, composer-sync, CS, Rector, PHPStan, PHPDoc, Igor, translations, coverage + 100% gate, demos, e2e). |
| REQ-TEST-003 / REQ-TEST-006 | `Makefile` `coverage-check`, `test-coverage-100`; `composer test-coverage-100` | 100% statements and elements of `src/`, no exclusions. |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method of `src/`. |
| REQ-TEST-011 | `demo/Makefile` `demo-smoke` | Demo boots and answers HTTP 200 on `:8085`. |
| REQ-DEMO-013 | `demo/symfony8/Makefile` `test-e2e`, `demo-screenshots` | Playwright e2e and WebP README screenshots. |
| REQ-DEMO-006 / REQ-DEMO-011 | `demo/symfony8/docker-compose.yml` | MySQL without host ports; Mailpit (SMTP on the Docker network, UI on `:8087`). |
| REQ-RUNTIME-001 | `src/Dsn/DsnHostGuard.php`, `src/Resend/ResendApiChecker.php`, `tests/Unit/Resend/ResendApiCheckerTest.php` | Bounded DNS lookups and Resend API deadline. |
| REQ-TWIG-004 / REQ-TWIG-005 | `Makefile` `check-twig-extra`, `check-no-raw-html-form` | Twig Extra installed; no raw form markup in bundle or demo Twig. |
| REQ-MAKE-004 | `Makefile` `validate-translations` | YAML syntax + key parity of the 7 locales. |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db` | Integration suite (Doctrine, SQLite) in the root php service, then `tests/Integration/Log` on the optional MySQL 8.4 `db` service of the root `docker-compose.yml` (`db` profile, `STORAGE_KIT_TEST_DATABASE_URL` exported). |
| REQ-SF-004 rule 7 | `tests/Integration/Routing/RoutesOptInTest.php` | `routing.controllers` alone exposes no bundle route. |
| REQ-UI-003 | `tests/Integration/Contract/PageContractTest.php` | UiKit page contract of every page. |

When you change scripted behavior, **update the existing `REQ-*` comment** if the ID still matches the rule, or **add a new `REQ-*`** and document it here and in the PR description.

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and this table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.


---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist: Engram for org-level compliance, this file for product + traceability expectations.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
