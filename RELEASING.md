# Releasing `parisek/lint-kit`

`lint-kit` is a public Composer package on [Packagist](https://packagist.org/). A release is a git tag. Packagist reads the tags; no manual upload.

## Install in a project

```bash
ddev composer require --dev "parisek/lint-kit:^0.1"
```

Before the Packagist submission, add a `vcs` repository entry to the project `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/parisek/lint-kit" }]
```

Composer then reads the tags straight from GitHub. Checked on a local copy: `^0.1` resolved to `v0.1.0`.

## One-time setup (the owner)

1. Choose the licence and add the `LICENSE` file. Packagist and a public repository both need it.
2. Submit `https://github.com/parisek/lint-kit` at packagist.org. Packagist then follows the tags through its webhook.
3. Set a tag protection rule on `v*` in the repository settings.

## Prerequisites

- `main` is green (`composer test`, the CI matrix).
- `CHANGELOG.md` lists the changes under `[Unreleased]`, grouped by rule set (`Core`, `WordPress`, `Drupal`).

## Procedure

1. **Pick the version** (semver). Before 1.0, a minor version may break.

   | Bump | When |
   | --- | --- |
   | MAJOR | A rule is removed or renamed, or its default behaviour changes so a project fails that passed |
   | MINOR | A new rule or a new rule set |
   | PATCH | A fix that removes a false positive or a false negative, or a documentation change |

2. **Open a release pull request.** Rename `[Unreleased]` in `CHANGELOG.md` to `[X.Y.Z] - YYYY-MM-DD` and add a new empty `[Unreleased]` above it. Title: `chore(release): vX.Y.Z`. `composer.json` carries no `version`; Composer reads the tag.
3. **The owner merges it.**
4. **Tag `main`:** `git tag vX.Y.Z && git push origin vX.Y.Z`.
5. **The `release` workflow runs.** It validates Composer, runs the tests and creates a GitHub Release from the `CHANGELOG.md` section. It fails when the section is missing.

## Rules

- A tag never moves and never gets deleted. A mistake is fixed with the next tag.
- One version number covers the three rule sets. A breaking change in one set is a MAJOR version for all (issue portadesign/tailwind-base#874, open question 2).
- `.gitattributes` keeps development files out of a project's `vendor/`.

## Not verified

- The `release` workflow. The first real tag is its first run.
