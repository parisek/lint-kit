# Contributing to lint-kit

## Run the tests

```bash
composer install
composer test                   # PHPUnit: fixture suite and preset tests
composer phpstan                # level 5, with a baseline
composer normalize --dry-run    # composer.json is normalized
```

CI runs these commands, `composer validate --no-check-publish` and `composer audit`. Run them before you push.

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

Pull requests are squash-merged. The title becomes the commit subject, so GitHub appends `(#N)`. The `pr-title` workflow checks that the title is a Conventional Commit (`feat`, `fix`, `docs`, `chore`, `refactor`, `perf`, `test`, `ci`, `build`, `revert`).

Add a line under `[Unreleased]` in `CHANGELOG.md` for each change that affects a rule. Records of decisions live in `docs/adr/`.

## Security

Report a vulnerability in private. Do not open a public issue. See `SECURITY.md`.

## AI agents

`AGENTS.md` holds the rules for AI coding assistants.

## Public repository

Do not put a client name, a client URL or a real template of a client in code, tests or fixtures.
