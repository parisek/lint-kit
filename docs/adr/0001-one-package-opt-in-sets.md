# 0001. One package for both CMSs, with opt-in rule sets

## Context

The lint rules for Twig and PHP are copied from `tailwind-base` into every project and again into every derived skeleton: 116 files under `static/tests/` (2026-10-07). A project loads each rule through a hand-kept `require_once` list, and `sync-skeleton` registers new rules in the project config.

Of 40 Twig rule classes, 6 mention WordPress, Timber or ACF and 4 mention Drupal, Paragraphs or Webform. 16 of 17 PHPStan files mention WordPress or Timber. A mention is a word in the file, not proof of a dependency. The first audit reads each rule.

Two layouts were possible: one package with a neutral core and one opt-in set for each CMS, or one package for each CMS.

## Decision

One package, `parisek/lint-kit`, public from the first commit. It has `Core`, `WordPress` and `Drupal`. A project chooses its sets in its config. A set of another CMS never loads, so it needs no stub of that CMS. The changelog lists changes for each set, so a WordPress-only fix is not noise for a Drupal project.

This mirrors the `theme/`, `wordpress/` and `drupal/` folders of the doctrine, which the same owner keeps.

## Consequences

- One version number covers three sets. A breaking change in one set forces a major version for all. If WordPress and Drupal diverge fast, the fallback is two packages. That is not done now, to avoid a split before the need is real.
- Rules and their tests and fixtures move out of every project. The package CI runs them once.
- A rule that mixes CMS-neutral and CMS-specific checks must be split or take the CMS as an option. The audit finds them.
- `verify-skeleton` and `sync-skeleton` must learn the new state (a pinned dependency instead of tracked files) before the first move.
