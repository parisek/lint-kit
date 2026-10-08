# Core

The CMS-neutral Twig rules. Every project loads this set. 39 rules live in `Twig/Rules/`.

A rule does not read project files. The only files it reads are the linted template and the `.yaml` next to it. Everything else arrives as an option from the preset (`Parisek\LintKit\Twig\Preset`).

Two rules take an option:

- `UnguardedOutputRule($extraRoots)`: template roots that never need a guard. The WordPress set adds `site`.
- `LinkFieldShapeRule($componentRoots)`: the directories that hold `<id>/<id>.yaml` definitions.

Each rule documents its predicate in its docblock, and has a fixture in `tests/Fixtures/Twig/`.
