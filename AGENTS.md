# AGENTS.md

Project instructions for AI coding assistants (Claude Code, Codex CLI, Cursor, Copilot, ...). Claude Code imports this file from `CLAUDE.md`. Human contributors: see `README.md` and `CONTRIBUTING.md`.

## Overview

`parisek/lint-kit` is a Composer package with the custom lint rules for Twig (`twig-cs-fixer`) and PHP (PHPStan) that `tailwind-base` and its downstream projects share. It serves WordPress and Drupal from one package: a CMS-neutral `Core` and one opt-in set for each CMS.

The requirements are in portadesign/tailwind-base#874 (R13.13 to R13.23). Cite the number in a commit message or a pull request when a change meets one.

## Configuration

```yaml
PACKAGE_NAME: "parisek/lint-kit"
PHP_REQUIRES: ">=8.3"
TESTS_DIR: "tests"
```

## Development Commands

```bash
composer install
composer test                   # PHPUnit
```

There is no `composer.lock` in git. CI resolves the latest tree the constraints allow, and `config.platform.php` pins the floor, as in `drupal-kit` (its ADR 0001).

## Layout

| Path | Holds |
| --- | --- |
| `src/Core/` | CMS-neutral rules. |
| `src/WordPress/` | Opt-in rules for WordPress. |
| `src/Drupal/` | Opt-in rules for Drupal. |
| `tests/Unit/` | One test class for each rule, with its fixtures. |
| `docs/adr/` | Decision records. |

## Rules

- **Autoload only.** No `require_once` list, no registration step in a project (R13.14).
- **A rule reads no project file.** The project config passes options in (R13.16).
- **A set of another CMS never loads.** It needs no stub of that CMS (R13.21).
- **Each rule enforces a doctrine file** in `tailwind-base`. Name that file in the rule docblock. A doctrine change that needs a new rule names the minimum version of this package (R13.19).
- **Tests and fixtures live here.** They run in this repository's CI. Projects do not copy them (R13.17).
- **No client data.** This repository is public. Use `example-site` in fixtures (R12.4 of the `test-kit` specification).

## Language

Everything in this repository is English, in ASD-STE100 style: one idea per sentence, active voice, present tense, one word for one meaning.

## PR + Review workflow

- One logical change for each pull request. A pull request is a draft, assigned to `parisek`. The owner marks it ready and merges it.
- The title is a Conventional Commit. Pull requests are squash-merged. The `pr-title` workflow checks the title.
- The commit body records what was rejected or left alone.
- After you open a pull request or push to it, wait for CI.

## Not decided yet

- The licence. Do not add a `LICENSE` file before the owner chooses one.
- Whether one version number is enough for the three sets (issue #874, open question 2).
- Which existing rules mix CMS-neutral and CMS-specific checks. The audit decides.
