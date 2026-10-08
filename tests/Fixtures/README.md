# Fixtures

A fixture is a Twig file with deliberately non-compliant cases. It states its own outcome in a header comment:

```
Expected: 4 warnings, 0 errors (EmptyAlt) — sections 1, 4, 6, 8
```

`tests/Unit/FixtureExpectationsTest.php` runs the linter on each fixture and compares the count **for that rule**. A total count is wrong, because rules overlap on purpose.

- `Twig/`: fixtures of the Core rules. `Twig/definitions/` holds component definitions for `LinkFieldShapeRule`.
- `WordPress/Twig/`: fixtures of the WordPress set.
- Some fixtures are path-sensitive (`metadata-yaml-parses-*`, `macro/`, `templates/`). Keep their folder structure.
- `when project.slug=tailwind-base` in an expectation means the count holds for that theme name. The test config uses it.
- Run one by hand: `vendor/bin/twig-cs-fixer lint --config tests/config/twig-wordpress.php tests/Fixtures/Twig/<file>`.

## Fixtures without an `Expected:` line

39 fixtures carry the line. These 15 do not. The test only checks that they lint without a crash:

`arbitrary-px`, `button-type`, `component-button-type`, `create-attribute-class-array`, `dump`, `home-url-link`, `prose-on-rich-text`, `space-xy`, `stretched-link-relative`, `transition-all`, `unguarded`, `unique-id-required` (and its three `ok` variants).

Their behaviour was checked once against the old rules (see the pull request of the move): the same findings, rule by rule and line by line. Giving each an `Expected:` line is open work.

**Check that a fixture exercises its rule.** Equal counts before and after a move prove nothing when both are zero. Count the findings of the rule the fixture is named after, and compare with its FAIL cases. The first check of that kind found one dead fixture: `component-content-scope-fixture.twig` documented three findings and produced none, upstream and after the move, because the rule is in scope only for `/component/<name>/<file>.twig`. It now lives under `component/content-scope/` and carries an `Expected:` line. The three `unique-id-required-fixture-ok-*` files are OK cases and produce no `UniqueIdRequired` finding on purpose.
