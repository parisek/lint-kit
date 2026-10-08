# Core

The CMS-neutral Twig rules. Every project loads this set. 40 rules live in `Twig/Rules/`.

A rule does not read project files. The only files it reads are the linted template and the `.yaml` next to it. Everything else arrives as an option from the preset (`Parisek\LintKit\Twig\Preset`).

Three rules take an option:

- `UnguardedOutputRule($extraRoots)`: template roots that never need a guard. The WordPress set adds `site`.
- `TranslationThemeNameRule($themeName)`: checks that the translation calls carry the project's text domain. The preset registers it when the `themeName` option is given. It is a Core rule because a Drupal project follows the same convention as a WordPress one (`_x('text', 'my-theme', 'my-theme')`).
- `LinkFieldShapeRule($componentRoots)`: the directories that hold `<id>/<id>.yaml` definitions.

Each rule documents its predicate in its docblock, and has a fixture in `tests/Fixtures/Twig/`.
