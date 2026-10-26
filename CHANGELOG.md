# Changelog

All notable changes are listed here, grouped by rule set once rules exist. The format follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.4.0] — 2026-10-26

### Changed

- `TypographyFilterRule` (Core) also reports a loop variable whose sequence is rooted at `content` or `heading`, or at such a loop variable: `{% for item in content.items %}{{ item.title }}{% endfor %}` now warns. The rule decides by the sequence, not by the name, so a loop over a menu or styleguide chrome stays out of scope, and an inner loop that reuses the name over another sequence shadows the outer one. Expect new warnings in an existing project. #13.

### Fixed

- `TypographyFilterRule` (Core) no longer reports a print inside a quoted HTML attribute value (`title="{{ content.title }}"`, `aria-label=…`, `id="input-{{ content.name }}"`). An attribute is a technical or accessibility string, and `|typography` does not belong there. The rule reads the template line for this, because the AST carries no HTML context; a value that opens on an earlier line than the print is still reported. #12.

## [0.3.0] — 2026-10-14

### Added

- `CountUpNumberInHtmlRule` (Core): warns when a `gsap-animation="count-up"` element ships no number, or only the start value, in its source text. A client that does not run the animation reads that text. portadesign/tailwind-base#920.

## [0.2.0] — 2026-10-08

### Changed

- **Breaking (0.x):** `TranslationThemeNameRule` moved from `Parisek\LintKit\WordPress\Twig\Rules` to `Parisek\LintKit\Core\Twig\Rules`. A Drupal project follows the same `_x('text', '<theme>', '<theme>')` convention, so the rule is not WordPress-only. The preset registers it when the `themeName` option is given, in any set. The `wordpress` set no longer needs `themeName`. A project that listed the old class in `removeRules` must use the new name.

### Added

- `tests/Fixtures/unverified.txt` and a test that pins the fixtures without an `Expected:` line, as the old Node runner did.

## [0.1.0] — 2026-10-08

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
