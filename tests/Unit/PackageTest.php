<?php

declare(strict_types=1);

namespace Parisek\LintKit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the package shape until the first rule exists: the name, the PSR-4 root and the three rule-set folders.
 */
final class PackageTest extends TestCase
{
    private function composer(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNameAndAutoloadRoot(): void
    {
        $composer = $this->composer();

        $this->assertSame('parisek/lint-kit', $composer['name']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Parisek\\LintKit\\']);
    }

    #[DataProvider('ruleSets')]
    public function testRuleSetFolderExists(string $folder): void
    {
        $this->assertDirectoryExists(__DIR__ . '/../../src/' . $folder);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ruleSets(): array
    {
        return ['core' => ['Core'], 'wordpress' => ['WordPress'], 'drupal' => ['Drupal']];
    }
}
