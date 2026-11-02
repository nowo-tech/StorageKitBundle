# Contributing

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Code style](#code-style)
- [Pull requests](#pull-requests)
- [Demo, e2e and screenshots](#demo-e2e-and-screenshots)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

1. Clone the repository.
2. Start the dev container: `make up` then `make install`.
3. Run tests: `make test`, `make test-coverage-100`, `make cs-check`, `make phpstan`, `make validate-phpdoc`, `make igor`.
4. Pre-release: `make release-check`.

## Code style

- PHP-CS-Fixer (PSR-12 + Symfony): `make cs-fix` / `make cs-check`.
- PHPStan (level 8) including **`nowo-tech/phpstan-frankenphp`** rulesets `classic` + `worker` (REQ-CS-005; `require-dev` only — not a runtime dependency for apps).
- PHPDoc and Markdown docs in **English**.
- Strict types in all PHP files.

## Pull requests

- Target the default branch.
- Ensure `make release-check` passes.
- Keep the changelog and docs updated when behaviour or config changes.

## Demo, e2e and screenshots

```bash
make -C demo up-symfony8   # FrankenPHP + MySQL + Mailpit on http://localhost:8085 (Mailpit UI :8087)
make test-e2e              # Playwright (demo/symfony8/e2e, official Playwright image, 1 worker)
make demo-screenshots      # refresh docs/images/demo/*.webp (PNG → WebP with sharp, PNGs deleted)
make demo-smoke            # boot, HTTP 200, down
make -C demo down-symfony8
```

Screenshots must show the demo masthead, the bundle output and the Symfony web debug toolbar (REQ-DEMO-013).

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter. Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
