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
composer test                   # PHPUnit: the fixture suite and the preset (about 5 s)
composer phpstan                # level 5, with a baseline
composer normalize --dry-run    # composer.json is normalized
```

There is no `composer.lock` in git. CI resolves the latest tree the constraints allow, and `config.platform.php` pins the floor, as in `drupal-kit` (its ADR 0001).

## Layout

| Path | Holds |
| --- | --- |
| `src/Core/Twig/Rules/` | CMS-neutral Twig rules (39). |
| `src/WordPress/Twig/Rules/` | Opt-in Twig rules for WordPress (1). |
| `src/Drupal/` | Opt-in rules for Drupal. Empty today. |
| `src/Twig/Preset.php` | Builds the `twig-cs-fixer` config of a project. The only public entry point. |
| `tests/Fixtures/` | The fixtures. Each one states its expected outcome. |
| `tests/config/` | The config the fixture suite lints with. |
| `tests/Unit/` | The fixture suite (`FixtureExpectationsTest`) and the preset tests. |
| `docs/adr/` | Decision records. |

## Rules

- **Autoload only.** No `require_once` list, no registration step in a project (R13.14).
- **A rule reads no project file.** The project config passes options in (R13.16).
- **A set of another CMS never loads.** It needs no stub of that CMS (R13.21).
- **Each rule enforces a doctrine file** in `tailwind-base`. Name that file in the rule docblock. A doctrine change that needs a new rule names the minimum version of this package (R13.19).
- **Tests and fixtures live here.** They run in this repository's CI. Projects do not copy them (R13.17).
- **No client data.** This repository is public. Use `example-site` in fixtures (R12.4 of the `test-kit` specification).

## Adding or changing a rule

1. Put the rule in `src/<Set>/Twig/Rules/<Name>Rule.php`. Name the doctrine file it enforces in the docblock.
2. **Register it in `Preset`.** Autoload finds the class, but only the preset runs it. `PresetTest` fails when a Core rule class is not registered.
3. Add a fixture in `tests/Fixtures/` with an `Expected:` line (`N warnings, 0 errors (RuleName)`) and FAIL and OK cases. Include the look-alike cases the rule must not catch.
4. Run `composer test`.

**The class short name is the rule identifier.** `twig-cs-fixer` builds `EmptyAlt` from the class `EmptyAltRule` by reflection. Disable comments (`{# twig-cs-fixer-disable-line EmptyAlt #}`) and reports use that name. Never rename a class without a major version.

**Changes against the upstream files** (the move of 2026-10-08), each with its reason:

- `UnguardedOutputRule`: `site` left the built-in roots and became the `$extraRoots` option, because `site` is a Timber global.
- `LinkFieldShapeRule`: definitions are searched in the `$componentRoots` given, not by offsets from `__DIR__`, because inside `vendor/` those offsets point into the package.
- `UniqueIdRequiredRule`, `TranslationPluralMissingFormatRule`: the messages no longer name one CMS. The logic is unchanged.
- Namespaces changed from `PortaDesign\TwigCsFixer\Rules` to `Parisek\LintKit\<Set>\Twig\Rules`. Class names did not change.
- Client names in docblocks and fixtures became neutral words, because this repository is public.

**PHPStan baseline.** `phpstan-baseline.neon` holds 15 findings in the moved rules. They are `instanceof` checks that guard two Twig versions (`ContextVariable` against `NameExpression`) and defensive checks in loops. They are not bugs. Remove the entries when the Twig floor makes the checks dead.

## Language

Everything in this repository is English, in ASD-STE100 style: one idea per sentence, active voice, present tense, one word for one meaning.

## PR + Review workflow

- One logical change for each pull request. A pull request is a draft, assigned to `parisek`. The owner marks it ready and merges it.
- The title is a Conventional Commit. Pull requests are squash-merged. The `pr-title` workflow checks the title.
- The commit body records what was rejected or left alone.
- After you open a pull request or push to it, wait for CI.

## Not decided yet

Decided: releases are git tags, published on Packagist (RELEASING.md).

- The licence. Do not add a `LICENSE` file before the owner chooses one.
- Whether one version number is enough for the three sets (issue #874, open question 2).
- The coding standard (`phpcs`). The moved rules use tabs. A standard needs a decision first.
- Whether the 15 fixtures without an `Expected:` line get one.
