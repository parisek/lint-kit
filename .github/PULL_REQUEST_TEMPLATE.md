## Summary

<!-- What changes and why. Cite the requirement (R13.x) if the change meets one. -->

## Checklist

- [ ] The title is a Conventional Commit.
- [ ] A new or changed rule has a fixture with an `Expected:` line, and `PresetTest` passes.
- [ ] `composer test`, `composer phpstan` and `composer normalize --dry-run` pass.
- [ ] `CHANGELOG.md` has a line under `[Unreleased]` when a rule changes.
- [ ] README or docs are updated when behavior changes.
- [ ] No client name or client data in code, tests or fixtures.
