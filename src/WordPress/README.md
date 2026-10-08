# WordPress

Opt-in set for WordPress projects. A Drupal project never loads it and never needs its stubs.

Today it holds no rule class. Choosing the set adds `site`, the Timber global, to the roots that need no guard in `UnguardedOutputRule`.

`TranslationThemeNameRule` was here in `0.1.0`. It moved to Core in `0.2.0`, because Drupal projects use the same translation convention.

The seven PHPStan rules of the old setup (`portadesign.wp.*`) move here as the last step of the plan.
