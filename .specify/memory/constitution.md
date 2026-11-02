# StorageKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Fail closed, secrets never leave in clear
The admin UI is private by default (roles, custom checker; actions also need the Instance Settings section access); an
empty roles list is refused; CSRF is mandatory on every mutation; the container refuses insecure wiring (no Instance
Settings, no framework mailer, no form CSRF, demo switch outside debug). DSNs and API keys are Instance Settings
secrets: never rendered, logged or shown — only redacted `scheme://host` labels; transport errors are scrubbed.

### VII. Safe outbound I/O
A stored DSN is accepted only with an allow-listed scheme (never sendmail / native / null) and a public host (SSRF
guard, bounded DNS); a refused DSN fails the send instead of falling back silently. Every outbound call (DNS, Resend
API) has a deadline.

### VIII. Worker-safe and privacy-aware
The DSN is read on every send; the only cached state is the transport of the last DSN, reset on `kernel.reset` and
settings changes. The sent-mail log keeps envelope data only by default, has a retention purge and an erasure command,
and never breaks a delivery.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-10-31 | **Last Amended**: 2026-10-31
