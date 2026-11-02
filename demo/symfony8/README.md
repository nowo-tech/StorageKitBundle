# Storage Kit Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default), MySQL and Mailpit showing `nowo-tech/storage-kit-bundle`:

- the `mailer` settings section contributed to Instance Settings (DSN encrypted at rest, sender, Reply-To, test
  recipient) with the "Send a test e-mail" action;
- the runtime DSN (`smtp://mailpit:1025`, allowed through `dsn.allowed_hosts`) replacing `MAILER_DSN=null://null`;
- a home page that sends through the plain `MailerInterface` and shows `nowo_storage_kit_status()`;
- the sent-mail log (`/admin/mail/log`) with seeded messages, one failed delivery and stored bodies (`store_bodies`
  is on in the demo only).

## Setup

```bash
make up      # containers + composer install, prints "Demo started at: http://localhost:8085"
make setup   # key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

Mailpit: http://localhost:8087.

## Commands

- `php bin/console app:demo:seed` – seed the settings and the log (idempotent)
- `php bin/console nowo:storage-kit:send-test qa@example.com` – synchronous test e-mail
- `php bin/console nowo:storage-kit:log:purge --older-than-days=7` – retention purge
- `make test` – `lint:container` + `lint:twig`
- `make test-e2e` / `make demo-screenshots` – Playwright (REQ-DEMO-013)
