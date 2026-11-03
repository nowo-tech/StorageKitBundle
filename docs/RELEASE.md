# Release process

## Table of contents

- [Before the first tag (1.0.0)](#before-the-first-tag-100)
- [Standard workflow](#standard-workflow)

## Before the first tag (1.0.0)

1. Independent AI security audit recorded with a passing grade (REQ-SEC-004) — see [SECURITY.md](SECURITY.md).
2. [CHANGELOG.md](CHANGELOG.md) — move `[Unreleased]` to `## [1.0.0] - YYYY-MM-DD`.
3. [UPGRADING.md](UPGRADING.md) — "To 1.0.0" section current.
4. [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) — audited revision set to the tag.
5. `make release-check` green (check-no-cursor-coauthor, check-open-prs, check-no-raw-html-form, composer-sync, cs-fix, cs-check, rector-dry, phpstan, validate-phpdoc, igor, test-coverage, coverage-check, demo release-check incl. `demo-smoke`, test-e2e).
6. CI green on the exact release commit.
7. Annotated tag `git tag -a v1.0.0 -m "Release v1.0.0"`; run `make check-no-cursor-coauthor` again before pushing (REQ-GIT-001).
8. Push branch and tag; `release.yml` creates the GitHub Release from the tag message and the changelog entry.
9. Confirm Packagist picked up the tag; submit the recipe to `symfony/recipes-contrib` if wanted.

## Standard workflow

1. Move `[Unreleased]` entries in [CHANGELOG.md](CHANGELOG.md) to a new `## [x.y.z] - YYYY-MM-DD` section.
2. Update [UPGRADING.md](UPGRADING.md) when hosts must act.
3. Update integrator docs ([USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [SECURITY.md](SECURITY.md)) when behaviour changes.
4. Run `make release-check`.
5. Commit, tag (`git tag -a vx.y.z -m "Release vx.y.z"`), push branch and tag.
6. Verify the GitHub Release and Packagist.

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).
