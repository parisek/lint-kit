# lint-kit

Status: **early scaffold**. The package holds no rule yet.

One Composer package for the Twig and PHPStan lint rules, shared by WordPress and Drupal projects: a CMS-neutral core and one opt-in rule set per CMS.

- Plan and requirements: [portadesign/tailwind-base#874](https://github.com/portadesign/tailwind-base/issues/874).
- Decision record: [`docs/adr/`](docs/adr/).

```bash
composer install
composer test
```

Related: [`test-kit`](https://github.com/parisek/test-kit), the testing and comparison tool.
