# WordPress

Opt-in rules for WordPress projects. A Drupal project never loads this set and never needs its stubs.

Today it holds one Twig rule, `TranslationThemeNameRule` (a dispatch table of WordPress translation functions that checks the text domain). The preset also adds `site`, the Timber global, to the roots that need no guard.

The seven PHPStan rules of the old setup (`portadesign.wp.*`) move here as the last step of the plan.
