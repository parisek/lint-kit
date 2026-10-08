# Contributing to lint-kit

## Run the tests

```bash
composer install
composer test
```

## Add a rule

1. Decide the set: Core, WordPress or Drupal. When a rule mixes CMS-neutral and CMS-specific checks, split it or take the CMS as an option.
2. Put the rule under `src/<Set>/Twig/Rules/`. Name the doctrine file it enforces in the docblock.
3. Register it in `src/Twig/Preset.php`. `PresetTest` fails when a Core rule is not registered.
4. Add a fixture under `tests/Fixtures/` with an `Expected:` line. See `tests/Fixtures/README.md`.
5. List the change in `CHANGELOG.md` under the set it belongs to.

## Open a pull request

1. Branch from `main`. Make one logical change.
2. Open a draft pull request assigned to `parisek`. Use a Conventional Commit as the title.
3. Wait for CI. Fix a failure or explain it.
4. The owner reviews and merges.

## Public repository

Do not put a client name, a client URL or a real template of a client in code, tests or fixtures.
