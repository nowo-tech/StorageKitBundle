# Demos

## Symfony 8 (`symfony8/`)

- Symfony **8.1**, FrankenPHP (PHP **8.5**, worker mode by default), MySQL 8 and **Mailpit**.
- App: http://localhost:8085 — status of the runtime mailer and a form that sends through `MailerInterface`
- Settings → Mailer: http://localhost:8085/admin/settings/sections/mailer (DSN `smtp://mailpit:1025`, "Send a test e-mail")
- Sent-mail log: http://localhost:8085/admin/mail/log
- Mailpit UI: http://localhost:8087

```bash
make up-symfony8              # start (prints "Demo started at: …")
make -C symfony8 setup        # encryption key, schema, demo settings and messages
make demo-smoke               # REQ-TEST-011
make -C symfony8 test-e2e     # Playwright
make down-symfony8
```

The admin UIs are open without login (`allow_unauthenticated: true`, demo only — refused without `kernel.debug`).
See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
