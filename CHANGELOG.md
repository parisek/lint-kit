# Changelog

All notable changes are listed here, grouped by rule set once rules exist. The format follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- **Core set:** 39 Twig rules moved from `tailwind-base` with their fixtures, in `Parisek\LintKit\Core\Twig\Rules`.
- **WordPress set:** `TranslationThemeNameRule`, and the `site` root for `UnguardedOutputRule`.
- `Parisek\LintKit\Twig\Preset::config()`: builds the `twig-cs-fixer` config, with options for sets, template roots, component roots, theme name, house style, project rules and removed rules.
- The fixture suite: every fixture runs through the linter and its `Expected:` line is checked per rule. 39 fixtures carry the line, 15 are smoke-tested. The `ComponentContentScope` fixture moved under `component/<name>/`, where the rule is in scope: at the old top-level place it fired nothing while claiming three findings.
- PHPStan (level 5, baseline) and `composer normalize` in CI.
- MIT licence (`LICENSE` and the `license` field of `composer.json`).
- `twig/twig` is a direct requirement (`^3.30`, the current Twig). The dead `instanceof ContextVariable` branches of six rules are gone; behaviour is unchanged (parity run). The PHPStan baseline went from 15 to 8 findings.
- Changed against the upstream files: `UnguardedOutputRule` (`site` is an option), `LinkFieldShapeRule` (`$componentRoots`), the messages of `UniqueIdRequiredRule` and `TranslationPluralMissingFormatRule` (no CMS named).

- Repository scaffold: package, rule-set folders, one guard test, CI, decision record.
- Release flow of `drupal-kit`: `RELEASING.md`, the `Stamp Release` and `Release` workflows, annotated tags. `.gitattributes` keeps development files out of `vendor/`.
