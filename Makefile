# Makefile for Storage Kit Bundle
# All dev targets use the root docker-compose.yml.

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
# Resolve `docker` via `command -v` so a local `docker/` directory cannot shadow the CLI when PATH has empty components.
DOCKER_BIN  := $(shell command -v docker 2>/dev/null || echo docker)
COMPOSE_BIN := $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install ensure-up test test-coverage test-coverage-100 coverage-check validate-phpdoc test-e2e demo-screenshots coverage-php-percent cs-check cs-fix qa clean release-check release-check-demos demo-smoke composer-sync rector rector-dry phpstan igor update validate assets setup-hooks check-no-cursor-coauthor check-open-prs check-no-raw-html-form check-twig-extra validate-translations test-with-db test-coverage-with-db db-up strip-cursor-coauthor-from-history

help:
	@echo "Storage Kit Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  down-dev      Stop containers (keep volumes; REQ-MAKE-007)"
	@echo "  build         Rebuild Docker image (no cache)"
	@echo "  shell         Open shell in container"
	@echo "  install       Install Composer dependencies"
	@echo "  assets        No-op (no frontend assets in this bundle)"
	@echo "  test          Run PHPUnit tests (starts container if needed)"
	@echo "  test-coverage Run tests with code coverage (starts container if needed)"
	@echo "  test-coverage-100 Run tests with coverage and fail below 100% (REQ-TEST-003 / REQ-TEST-006)"
	@echo "  coverage-check Fail when the last coverage.xml is below 100% statements/elements"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  rector        Apply Rector refactoring"
	@echo "  rector-dry    Run Rector in dry-run mode"
	@echo "  phpstan       Run PHPStan static analysis"
	@echo "  igor          Run Igor worker-state audit (REQ-CS-008)"
	@echo "  validate-phpdoc Fail when a class or public method in src/ has no PHPDoc (REQ-CS-001)"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "  release-check Pre-release: ensure-up, co-author/open-PR/raw-form checks, composer-sync, cs-fix, cs-check, rector-dry, phpstan, validate-phpdoc, igor, validate-translations, test-coverage + 100% gate, demos (+ e2e)"
	@echo "  check-no-raw-html-form No raw <form>/<input>/<textarea>/<select> in bundle or demo Twig (REQ-TWIG-005)"
	@echo "  check-twig-extra       twig/extra-bundle in the package and every Twig demo (REQ-TWIG-004)"
	@echo "  validate-translations  YAML syntax + key parity of the 7 locales (REQ-MAKE-004 / REQ-I18N-002)"
	@echo "  test-with-db           Integration suite on SQLite + log tests on the root-compose MySQL 8.4 (db profile; REQ-TEST-005)"
	@echo "  test-coverage-with-db  Lifecycle tests on the root-compose MySQL 8.4 with text coverage (REQ-TEST-005)"
	@echo "  check-open-prs Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  demo-smoke    REQ-TEST-011: boot demo + HTTP 200 (make -C demo demo-smoke)"
	@echo "  composer-sync Validate composer.json and align composer.lock (no install)"
	@echo "  clean         Remove vendor and cache"
	@echo "  update        Update composer.lock (composer update)"
	@echo "  validate      Run composer validate --strict"
	@echo "  setup-hooks   Install git hooks (REQ-GIT-001)"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  test-e2e         Playwright e2e against demo/symfony8 (REQ-DEMO-013)"
	@echo "  demo-screenshots Refresh docs/images/demo/*.webp from the running demo (REQ-DEMO-013)"
	@echo ""
	@echo "Demos: make -C demo (up-symfony8, down-symfony8, demo-smoke, release-check)"
	@echo ""

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-interaction
	@echo "✅ Container ready!"

down:
	$(COMPOSE) --profile db down

# Stop containers without removing volumes (REQ-MAKE-007)
down-dev:
	$(COMPOSE) --profile db down --remove-orphans

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	@echo "✅ Dependencies installed."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (root $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

# REQ-TEST-003 / REQ-TEST-006: 100% statements and elements of src/ (no exclusions), non-zero exit below.
coverage-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/check-coverage.php coverage.xml --min-percent=100

test-coverage-100: test-coverage coverage-check

# REQ-CS-001: every class and public method of src/ has a PHPDoc block.
validate-phpdoc: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate-phpdoc

# REQ-DEMO-013: Playwright e2e and README screenshots (demo/symfony8).
test-e2e:
	$(MAKE) -C demo/symfony8 test-e2e

demo-screenshots:
	$(MAKE) -C demo/symfony8 demo-screenshots

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer igor
# REQ-MAKE-002: sequential chain, ensure-up first.
release-check:
	@$(MAKE) ensure-up
	@$(MAKE) check-no-cursor-coauthor
	@$(MAKE) check-open-prs
	@$(MAKE) check-no-raw-html-form
	@$(MAKE) check-twig-extra
	@$(MAKE) composer-sync
	@$(MAKE) cs-fix
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) validate-phpdoc
	@$(MAKE) igor
	@$(MAKE) validate-translations
	@$(MAKE) test-coverage
	@$(MAKE) coverage-check
	@$(MAKE) release-check-demos
	@$(MAKE) test-e2e

# REQ-TWIG-004: twig/extra-bundle in the package and in every demo that renders Twig.
check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# REQ-MAKE-004 / REQ-I18N-002: YAML syntax + key parity (en, es, it, fr, pt, de, nl).
validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

# REQ-TEST-005: the Integration suite on SQLite, then the lifecycle tests (tests/Integration/Lifecycle: purge, references, moves) on the MySQL 8.4
# service of the root compose (`db` profile, data in tmpfs), like the "Lifecycle on …" CI jobs.
STORAGE_KIT_TEST_DATABASE_URL ?= mysql://root:ci-throwaway@db:3306/storagekit?serverVersion=8.4&charset=utf8mb4

db-up:
	$(COMPOSE) --profile db up -d --wait db

test-with-db: ensure-up db-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --testsuite Integration
	$(COMPOSE) exec -T -e STORAGE_KIT_TEST_DATABASE_URL='$(STORAGE_KIT_TEST_DATABASE_URL)' $(SERVICE_PHP) vendor/bin/phpunit --no-coverage tests/Integration/Lifecycle

test-coverage-with-db: ensure-up db-up
	$(COMPOSE) exec -T -e STORAGE_KIT_TEST_DATABASE_URL='$(STORAGE_KIT_TEST_DATABASE_URL)' $(SERVICE_PHP) vendor/bin/phpunit tests/Integration/Lifecycle --coverage-text

# REQ-TWIG-005: no raw <form>/<input>/<textarea>/<select> in bundle or demo Twig templates.
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

release-check-demos:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip release-check-demos"; fi

# REQ-TEST-011: demo smoke (HTTP 200); also part of demo release-check
demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo demo-smoke; else echo "No demo/Makefile — skip demo-smoke"; fi

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle (pages use UiKit assets)."



setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (optional include: monorepo path exists locally; absent on GitHub Actions checkout)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

# REQ-REL-003 / REQ-MAKE-002
check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
