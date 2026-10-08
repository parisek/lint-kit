<?php

declare(strict_types=1);

// The config the fixture suite lints with: the Core set plus the WordPress set,
// like the old tailwind-base config.php that registered every rule. The theme
// name is the value the fixtures state in their `when project.slug=...` clause.

use Parisek\LintKit\Twig\Preset;

require_once __DIR__ . '/../../vendor/autoload.php';

return Preset::config([
    'templates' => [__DIR__ . '/../Fixtures/Twig'],
    'componentRoots' => [__DIR__ . '/../Fixtures/Twig/definitions'],
    'sets' => ['core', 'wordpress'],
    'themeName' => 'tailwind-base',
]);
