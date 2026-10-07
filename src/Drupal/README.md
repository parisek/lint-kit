# Drupal

Opt-in rules for Drupal projects (Paragraphs, Webform, render arrays). A WordPress project never loads this set.

Rules load by PSR-4 autoload under `Parisek\LintKit\Drupal\`. A rule does not read project files. The project config passes its options in (issue portadesign/tailwind-base#874, R13.14 to R13.16).

Status: planned. The first audit sorts every existing rule into Core, WordPress or Drupal.
