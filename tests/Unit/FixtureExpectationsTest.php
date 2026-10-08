<?php

declare(strict_types=1);

namespace Parisek\LintKit\Tests\Unit;

use Parisek\LintKit\Tests\Support\TwigLint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every lint fixture through the linter and asserts the `Expected:` line it documents.
 *
 * A fixture is a deliberately non-compliant sample that states its own outcome. Without this test
 * nothing checks that line, and a rule that quietly stops firing leaves every fixture claiming it works.
 * The count is per rule (see {@see TwigLint}).
 *
 * The old runner tested only the fixture files directly inside the fixtures folder. This one also
 * tests the files inside the sub-folders (`metadata-yaml-parses-*`, `macro`, `templates`), because
 * the rules that look at the parent folder name can only be exercised there.
 */
final class FixtureExpectationsTest extends TestCase
{
    private const ROOTS = ['Twig', 'WordPress/Twig'];

    /**
     * @return array<string, array{string}>
     */
    public static function withExpectation(): array
    {
        return self::fixtures(true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function withoutExpectation(): array
    {
        return self::fixtures(false);
    }

    #[DataProvider('withExpectation')]
    public function testFixtureMatchesItsExpectation(string $file): void
    {
        $expected = TwigLint::expectation((string) file_get_contents($file));
        $this->assertNotNull($expected);

        if (null !== $expected['requires']) {
            if ('project.slug' !== $expected['requires']['key']) {
                $this->fail(\sprintf('Unknown "when" key "%s" in %s.', $expected['requires']['key'], $file));
            }
            if (TwigLint::PROJECT_SLUG !== $expected['requires']['value']) {
                $this->markTestSkipped(\sprintf('The count holds only for project.slug=%s.', $expected['requires']['value']));
            }
        }

        $result = TwigLint::run($file);
        $actual = TwigLint::count($result['output'], $expected['rule']);

        $this->assertSame(
            ['warnings' => $expected['warnings'], 'errors' => $expected['errors']],
            $actual,
            \sprintf("%s: expected findings for rule %s differ.\n%s", basename($file), $expected['rule'], $result['output'])
        );
    }

    /**
     * A fixture without an `Expected:` line opted out of the count. It must still lint without a crash.
     */
    #[DataProvider('withoutExpectation')]
    public function testFixtureWithoutExpectationDoesNotCrash(string $file): void
    {
        $result = TwigLint::run($file);

        $this->assertArrayHasKey('exit', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    private static function fixtures(bool $withExpectation): array
    {
        $found = [];
        foreach (self::ROOTS as $root) {
            $base = \dirname(__DIR__) . '/Fixtures/' . $root;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                    continue;
                }
                $has = null !== TwigLint::expectation((string) file_get_contents($file->getPathname()));
                if ($has === $withExpectation) {
                    $found[substr($file->getPathname(), \strlen(\dirname(__DIR__) . '/Fixtures/'))] = [$file->getPathname()];
                }
            }
        }
        ksort($found);

        return $found;
    }
}
