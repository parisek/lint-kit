# WordPress

Opt-in rules for WordPress projects (Timber, ACF, WPML). A Drupal project never loads this set and never needs its stubs.

Rules load by PSR-4 autoload under `Parisek\LintKit\WordPress\`. A rule does not read project files. The project config passes its options in (issue portadesign/tailwind-base#874, R13.14 to R13.16).

Status: planned. The first audit sorts every existing rule into Core, WordPress or Drupal.
