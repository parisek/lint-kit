<?php

declare(strict_types=1);

namespace Parisek\LintKit\Tests\Support;

/**
 * Runs `twig-cs-fixer lint` on one file and counts what one rule reports.
 *
 * The count filters on the rule identifier. Comparing the expected number with the
 * total for the file is wrong: rules overlap on purpose, so one line can draw
 * findings from three rules, and a total-count comparison reports healthy
 * fixtures as drifted. `--debug --report=github` is the only output that carries
 * the severity and the rule identifier on one line:
 *
 *     ::warning file=...,line=35::EmptyAlt.EmptyAlt:35 -- Empty alt attribute ...
 */
final class TwigLint
{
    public const PROJECT_SLUG = 'tailwind-base';

    /**
     * @return array{output: string, exit: int}
     */
    public static function run(string $file): array
    {
        $root = \dirname(__DIR__, 2);
        $command = [
            \PHP_BINARY,
            $root . '/vendor/bin/twig-cs-fixer',
            'lint',
            '--debug',
            '--report=github',
            '--config=' . $root . '/tests/config/twig-wordpress.php',
            $file,
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start twig-cs-fixer.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $output = '' !== trim($stdout) ? $stdout : $stderr;

        // A linter exits non-zero when it finds something, which is the normal case for a fixture.
        // What marks a crash is output that does not look like a report. A crash must never read
        // as "0 findings", or a fixture that expects 0 would pass while its linter never ran.
        if (0 !== $exit && !str_contains($output, '::warning ') && !str_contains($output, '::error ')) {
            throw new \RuntimeException(\sprintf("twig-cs-fixer did not produce a report (exit %d):\n%s", $exit, substr($output, 0, 2000)));
        }

        return ['output' => $output, 'exit' => $exit];
    }

    /**
     * @return array{warnings: int, errors: int}
     */
    public static function count(string $output, string $rule): array
    {
        $warnings = 0;
        $errors = 0;
        foreach (explode("\n", $output) as $line) {
            // The location part varies (`line=33` alone, or `line=33,col=5`), so it is skipped lazily.
            if (1 !== preg_match('/^::(warning|error) .*?::([A-Za-z0-9_]+)\./', $line, $match)) {
                continue;
            }
            // Exact match, never a prefix: `ScaleValue` must not absorb a future `ScaleValueStrict`.
            if ($match[2] !== $rule) {
                continue;
            }
            'warning' === $match[1] ? ++$warnings : ++$errors;
        }

        return ['warnings' => $warnings, 'errors' => $errors];
    }

    /**
     * Parses the `Expected:` line of a fixture.
     *
     *     Expected: 4 warnings, 0 errors (EmptyAlt) -- sections 1, 4, 6, 8
     *     Expected: 12 warnings, 0 errors (TranslationThemeName) when project.slug=tailwind-base
     *
     * @return array{warnings: int, errors: int, rule: string, requires: array{key: string, value: string}|null}|null
     */
    public static function expectation(string $source): ?array
    {
        $pattern = '/Expected:\s*(\d+)\s+warnings?,\s*(\d+)\s+errors?\s*\(([^)]+)\)(?:\s+when\s+([A-Za-z0-9_.]+)\s*=\s*(\S+))?/';
        if (1 !== preg_match($pattern, $source, $match)) {
            return null;
        }

        return [
            'warnings' => (int) $match[1],
            'errors' => (int) $match[2],
            'rule' => trim($match[3]),
            'requires' => isset($match[4]) && '' !== $match[4] ? ['key' => $match[4], 'value' => $match[5]] : null,
        ];
    }
}
