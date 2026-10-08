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
    private const ROOTS = ['Twig'];

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
     * The fixtures without an `Expected:` line are an opt-out, and an opt-out must be visible. This
     * pins the exact set (tests/Fixtures/unverified.txt): a new fixture without a line, or a lost
     * line on an old one, fails here and names the file. The floor stops a change that empties the
     * fixtures AND the list from satisfying the parity check with two empty sets.
     */
    public function testTheFixturesWithoutAnExpectationAreExactlyTheDeclaredOnes(): void
    {
        $base = \dirname(__DIR__) . '/Fixtures/';
        $declared = array_values(array_filter(array_map(
            static fn (string $line): string => trim((string) preg_replace('/#.*$/', '', $line)),
            file($base . 'unverified.txt', \FILE_IGNORE_NEW_LINES) ?: []
        )));
        sort($declared);

        $actual = array_map(
            static fn (array $row): string => substr($row[0], \strlen($base)),
            array_values(self::withoutExpectation())
        );
        sort($actual);

        $this->assertSame($declared, $actual, 'The fixtures without an Expected: line changed. A new fixture needs one; if the opt-out is deliberate, list it in tests/Fixtures/unverified.txt.');
        $this->assertGreaterThanOrEqual(30, \count(self::withExpectation()), 'Expected 30 or more fixtures with an Expected: line.');
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
