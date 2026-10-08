# Core

The CMS-neutral rules. Every project loads this set. Twig rules that make no assumption about WordPress or Drupal live here.

Rules load by PSR-4 autoload under `Parisek\LintKit\Core\`. A rule does not read project files. The project config passes its options in (issue portadesign/tailwind-base#874, R13.14 to R13.16).

Status: planned. The first audit sorts every existing rule into Core, WordPress or Drupal.
