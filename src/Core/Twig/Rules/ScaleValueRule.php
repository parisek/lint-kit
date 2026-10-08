<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an arbitrary `prefix-[Npx]` utility has a Tailwind scale equivalent.
 *
 * Project convention: prefer the named utility (`p-4`) over the arbitrary form
 * (`p-[16px]`). The arbitrary form is needed when no scale equivalent exists
 * — non-standard font sizes (e.g. `text-[50px]`) or off-grid spacing — but
 * for canonical 4 px multiples the named utility is shorter, semantically
 * meaningful, and survives theme-token edits.
 *
 * Token-level port of the Python `use-scale-value` regex. Hybrid scan over:
 *  - `TEXT_TYPE` — raw HTML class attributes like `class="p-[16px]"`.
 *  - `STRING_TYPE` — Twig string literals carrying class fragments inside
 *    `classes:` arrays / `create_attribute()` calls.
 *
 * The regex matches `prefix-[Npx]` with a leading word boundary so that
 * arbitrary values nested inside complex utilities (`bg-[url(/foo)]`,
 * `before:p-[16px]`) still trigger on the suffix portion. Each match within
 * a token fires independently — `class="p-[16px] m-[24px]"` produces two
 * warnings.
 *
 * Scope mirrors the Python check's two lookup tables:
 *  - **Spacing/sizing** (`p`, `m`, `gap`, `w`, `h`, `top`, `inset`, …) share
 *    a single px → integer-multiplier map (16 → `4`, 20 → `5`, etc.).
 *  - **Font sizes** (`text`) use a named-scale map (12 → `xs`, 16 → `base`,
 *    50 → no equivalent → silent).
 *
 * Future widening (Phase 2): Tailwind v4 spacing is dynamically derived
 * from `--spacing: 0.25rem`, so any 4 px multiple is a valid named utility
 * (e.g. `p-7` for 28 px). The current map only carries values that already
 * existed in v3 to stay strictly equivalent to the Python check.
 */
final class ScaleValueRule extends AbstractRule
{
	/**
	 * Spacing/sizing prefixes that share the px → multiplier map.
	 *
	 * Each entry maps a `prefix.split('-')[0]` lookup key (so `gap-x` resolves
	 * via `gap`, `min-w` via `min`) — same shape as the Python implementation.
	 */
	private const SPACING_PREFIX_KEYS = [
		'p', 'px', 'py', 'pt', 'pr', 'pb', 'pl',
		'm', 'mx', 'my', 'mt', 'mr', 'mb', 'ml',
		'gap',
		'w', 'min', 'max',
		'h',
		'top', 'right', 'bottom', 'left',
		'inset',
	];

	/** @var array<int, string> */
	private const SPACING_MAP = [
		16 => '4',
		20 => '5',
		24 => '6',
		32 => '8',
		40 => '10',
		48 => '12',
		64 => '16',
		80 => '20',
		96 => '24',
	];

	/** @var array<int, string> */
	private const TEXT_MAP = [
		12 => 'xs',
		14 => 'sm',
		16 => 'base',
		18 => 'lg',
		20 => 'xl',
		24 => '2xl',
		30 => '3xl',
		36 => '4xl',
		48 => '5xl',
		60 => '6xl',
		72 => '7xl',
		96 => '8xl',
		128 => '9xl',
	];

	private const PATTERN = '/(?<![a-zA-Z0-9_-])([a-z]+(?:-[a-z]+)?)-\[(\d+)px\]/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		$type = $token->getType();
		if (Token::TEXT_TYPE !== $type && Token::STRING_TYPE !== $type) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, 'px]')) {
			return;
		}

		if (!preg_match_all(self::PATTERN, $value, $matches, PREG_SET_ORDER)) {
			return;
		}

		foreach ($matches as $match) {
			$full = $match[0];
			$prefix = $match[1];
			$pxValue = (int) $match[2];

			$suggestion = $this->lookup($prefix, $pxValue);
			if (null === $suggestion) {
				continue;
			}

			$this->addWarning(
				sprintf('%s can be written as %s', $full, $suggestion),
				$token,
				'ScaleValue',
			);
		}
	}

	private function lookup(string $prefix, int $pxValue): ?string
	{
		$lookupKey = false !== strpos($prefix, '-') ? explode('-', $prefix)[0] : $prefix;

		if ('text' === $lookupKey) {
			return isset(self::TEXT_MAP[$pxValue]) ? 'text-' . self::TEXT_MAP[$pxValue] : null;
		}

		if (!\in_array($lookupKey, self::SPACING_PREFIX_KEYS, true)) {
			return null;
		}

		if (!isset(self::SPACING_MAP[$pxValue])) {
			return null;
		}

		return $prefix . '-' . self::SPACING_MAP[$pxValue];
	}
}
