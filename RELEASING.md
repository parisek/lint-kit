# Releasing `parisek/lint-kit`

Tag-driven release flow, the same as `parisek/drupal-kit`. The package is published on [Packagist](https://packagist.org/packages/parisek/lint-kit). Composer reads git tags through Packagist's auto-sync webhook. No manual registry upload.

## Install in a project

```bash
ddev composer require --dev "parisek/lint-kit:^0.1"
```

Before the Packagist submission, add a `vcs` repository entry to the project `composer.json`, and drop it after the submission:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/parisek/lint-kit" }]
```

Checked on a local copy: Composer resolved `^0.1` to `v0.1.0` through such an entry.

## One-time setup (the owner)

1. Submit `https://github.com/parisek/lint-kit` at packagist.org and enable the GitHub webhook.
2. Set a tag protection rule on `v*` in the repository settings.
3. Let the `github-actions` bot push to `main`. The `Stamp Release` workflow commits the stamped changelog directly. A branch protection rule that blocks it breaks the automatic path.

## Prerequisites

- `main` is green:

  ```bash
  composer test
  ```

- `CHANGELOG.md` has changes under `[Unreleased]`, grouped by rule set (`Core`, `WordPress`, `Drupal`).
- All review threads on pull requests merged into this version are resolved.

## Procedure

### 1. Pick the version number (semver)

| Bump | When |
| --- | --- |
| **MAJOR** | A rule is removed or renamed, or its default behaviour changes so a project fails that passed |
| **MINOR** | A new rule or a new rule set |
| **PATCH** | A fix that removes a false positive or a false negative, or a documentation change |

Before 1.0, a minor version may break. One version number covers the three rule sets, so a breaking change in one set is a MAJOR version for all (issue portadesign/tailwind-base#874, open question 2).

### 2. Release

**Automatic (preferred).** Run the `Stamp Release` workflow (Actions, Run workflow) with the version without the `v` prefix, for example `0.1.0`. It checks the version and the changelog, runs the tests, stamps `[Unreleased]` to `[X.Y.Z] — date`, commits, creates the annotated tag, pushes both, and dispatches the `Release` workflow.

**Manual.** Stamp the changelog and push to `main`. Then tag:

```bash
git tag -a vX.Y.Z -m "vX.Y.Z: <one-line summary>"
git push origin vX.Y.Z
```

`-a` (annotated) is mandatory. A lightweight tag lacks the metadata that Composer's VCS driver, Packagist and the GitHub release page expect. Tag the actual `main` HEAD. Never tag a feature branch.

### 3. GitHub release

The `Release` workflow takes the notes from the matching changelog section and the pull requests between tags. It marks the release Latest only when the tag is the highest semver. Do not run `gh release create` by hand unless the workflow fails: it would conflict with the workflow.

### 4. Verify the Packagist sync (about 30 seconds after the tag)

```bash
curl -s https://repo.packagist.org/p2/parisek/lint-kit.json | python3 -c "import sys,json; print(json.load(sys.stdin)['packages']['parisek/lint-kit'][0]['version'])"
```

It prints `vX.Y.Z`. If not, check the "Last update" on the package page. A lag of more than a few minutes means the webhook is wrong.

## Rules

- **Do not reuse a tag number.** A force-updated tag does not refresh Packagist's cache, and Composer caches and lock files may already point to the old object. Bump the patch instead.
- **Strict semver tags.** The `Release` workflow refuses a tag with a suffix, such as `v1.2.3-rc1`.
- **`.gitattributes` keeps development files out of `vendor/`.** `git archive` of a tag lists only what a project runs.

## Not verified

- The `Stamp Release` and `Release` workflows have not run in this repository. They are copies of the `drupal-kit` workflows without the Drupal and PHPStan steps, and `actionlint` reports no problem. The first real release is their first run.
