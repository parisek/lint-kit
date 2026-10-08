# lint-kit

Status: **alpha**. The Twig rules of the Core and WordPress sets are in. The PHPStan rules follow.

One Composer package for the Twig and PHPStan lint rules, shared by WordPress and Drupal projects: a CMS-neutral core and one opt-in rule set per CMS.

| Set | Twig rules | PHPStan rules |
| --- | --- | --- |
| `core` | 39 | 0 |
| `wordpress` | 1 | planned (7) |
| `drupal` | 0 (empty today) | 0 |

## Use

A project config is a few lines. It replaces a hand-kept list of `require_once` and `addRule()` calls.

```php
// twig-cs-fixer.php
return \Parisek\LintKit\Twig\Preset::config([
    'templates' => [__DIR__ . '/templates'],
    'sets' => ['core', 'wordpress'],   // 'core' alone is the default
    'themeName' => 'my-theme',         // required with the 'wordpress' set
]);
```

```bash
vendor/bin/twig-cs-fixer lint --config twig-cs-fixer.php
```

The options are listed in [`src/Twig/Preset.php`](src/Twig/Preset.php).

## Install

After the first tag and the Packagist submission (see [`RELEASING.md`](RELEASING.md)):

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

Related: [`test-kit`](https://github.com/parisek/test-kit), the testing and comparison tool.
