# lint-kit

[![Packagist Version](https://img.shields.io/packagist/v/parisek/lint-kit)](https://packagist.org/packages/parisek/lint-kit)
[![PHP](https://img.shields.io/packagist/php-v/parisek/lint-kit)](https://packagist.org/packages/parisek/lint-kit)
[![CI](https://github.com/parisek/lint-kit/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/parisek/lint-kit/actions/workflows/ci.yml)
[![Twig](https://img.shields.io/badge/Twig-3.30%2B-bacf29?logo=twig&logoColor=white)](https://twig.symfony.com)
[![License: MIT](https://img.shields.io/packagist/l/parisek/lint-kit)](LICENSE)

Status: **alpha**. The Twig rules are in. The PHPStan rules follow.

One Composer package for the Twig and PHPStan lint rules, shared by WordPress and Drupal projects: a CMS-neutral core and one opt-in rule set per CMS.

| Set | Twig rules | PHPStan rules |
| --- | --- | --- |
| `core` | 40 | 0 |
| `wordpress` | 0 (adds the `site` root) | planned (7) |
| `drupal` | 0 (empty today) | 0 |

## Use

A project config is a few lines. It replaces a hand-kept list of `require_once` and `addRule()` calls.

```php
// twig-cs-fixer.php
return \Parisek\LintKit\Twig\Preset::config([
    'templates' => [__DIR__ . '/templates'],
    'sets' => ['core', 'wordpress'],   // 'core' alone is the default
    'themeName' => 'my-theme',         // the text domain; turns on TranslationThemeNameRule
]);
```

```bash
vendor/bin/twig-cs-fixer lint --config twig-cs-fixer.php
```

The options are listed in [`src/Twig/Preset.php`](src/Twig/Preset.php).

## Install

The package is on Packagist: [packagist.org/packages/parisek/lint-kit](https://packagist.org/packages/parisek/lint-kit). A project needs no `repositories` entry. The release procedure is in [`RELEASING.md`](RELEASING.md).

```bash
composer require --dev "parisek/lint-kit:^0.1"
```

## Develop

```bash
composer install
composer test        # PHPUnit: the fixture suite, the preset
composer phpstan
```

- Plan and requirements: [portadesign/tailwind-base#874](https://github.com/portadesign/tailwind-base/issues/874) (private repository).
- Decision record: [`docs/adr/`](docs/adr/).
- Fixtures: [`tests/Fixtures/README.md`](tests/Fixtures/README.md).

## Licence

MIT. See [`LICENSE`](LICENSE).

Related: [`test-kit`](https://github.com/parisek/test-kit), the testing and comparison tool.
