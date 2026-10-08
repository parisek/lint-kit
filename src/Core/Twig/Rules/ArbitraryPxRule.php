<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when spacing / sizing utilities carry arbitrary `[Npx]` values.
 *
 * Tailwind v4 ships a rem-based spacing scale (1 unit = 0.25rem); the
 * project's Unit Doctrine (see `.claude/rules/theme/tailwindcss.md` § Unit
 * Doctrine) requires spacing / sizing classes to pick from the named scale
 * — `min-h-150` not `min-h-[600px]`, `pt-45` not `pt-[180px]`, `w-18`
 * not `w-[72px]`. The rem scale preserves proportionality when the root
 * font-size is tweaked (accessibility users zooming text-only); pixel
 * arbitraries freeze the size against the rest of the design system.
 *
 * The rule is warn-only on initial rollout (per `theme/twig-cs-fixer.md`
 * § 7.2 *Rollout — warn-only first*). The pattern has wide reach; promote
 * to error severity only after one sprint of clean CI.
 *
 * Hybrid scan over:
 *  - **TEXT_TYPE** — raw HTML class attributes.
 *  - **STRING_TYPE** — Twig string literals inside `classes:` arrays /
 *    `create_attribute()` calls.
 *
 * Targeted spacing / sizing utilities:
 *   w, h, size, min-w, min-h, max-w, max-h,
 *   m, mx, my, mt, mr, mb, ml,
 *   p, px, py, pt, pr, pb, pl,
 *   gap, gap-x, gap-y,
 *   top, right, bottom, left,
 *   inset, inset-x, inset-y,
 *   space-x, space-y,
 *   translate-x, translate-y
 *
 * Explicitly EXCLUDED (separate concerns, sub-pixel precision matters):
 *   border, border-{x,y,t,r,b,l}, outline, ring, divide
 *   text, leading, tracking, indent (handled by HeadingArbitraryFontSizeRule
 *     and project preference for `text-[Nrem]` in hand-tuned typography)
 *
 * False-positive guards:
 *  - `calc()` expressions are skipped — `min-h-[calc(100vh-80px)]` legitimately
 *    needs sub-pixel control; the embedded px is part of a calc, not a primary
 *    value. The pattern's `\[` anchor matches only the OUTER bracket.
 *  - `var()` expressions skipped — `min-h-[var(--brand-mega)]` is a design-system
 *    indirection, not an arbitrary literal.
 *  - Non-`px` units (`[Nrem]`, `[Nem]`, `[N%]`) are out of scope by pattern.
 *
 * Variant prefixes (`lg:min-h-[600px]`, `hover:pt-[8px]`) still trigger —
 * the underlying utility is still arbitrary. Per-line dedupe means a single
 * line with multiple matches (`w-[72px] h-[45px]`) fires once.
 */
final class ArbitraryPxRule extends AbstractRule
{
	/**
	 * Matches a spacing/sizing utility prefix followed immediately by
	 * `[Npx]` (where N can be int or decimal, optionally negative).
	 *
	 * Lookbehind `(?<![a-zA-Z0-9_-])` blocks compound-identifier suffixes
	 * (`my-w-[1px]`, `pre-pt-[1px]`) while still allowing variant prefixes
	 * (`lg:pt-[1px]`, `hover:w-[1px]`) because `:` is not in the class.
	 *
	 * The prefix alternation lists each utility family explicitly so we
	 * don't accidentally swallow `border-[1px]` / `text-[1px]` / `ring-[1px]`
	 * — they're sub-pixel precision contexts, not spacing.
	 *
	 * Group 1: the matched utility prefix (used in the warning message).
	 * Group 2: the px value (used in the warning message + future auto-fix).
	 */
	private const PATTERN = '/(?<![a-zA-Z0-9_-])('
		. 'min-[wh]|max-[wh]|size|[wh]|'
		. 'm[xytrbl]?|p[xytrbl]?|'
		. 'gap(?:-[xy])?|'
		. 'space-[xy]|'
		. 'translate-[xy]|'
		. 'inset(?:-[xy])?|'
		. 'top|right|bottom|left'
		. ')-\[(-?\d+(?:\.\d+)?)px\]/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (!$this->isScannable($token)) {
			return;
		}

		$value = $token->getValue();

		// Cheap pre-check: bail when no `px]` ever appears in the token.
		if (false === strpos($value, 'px]')) {
			return;
		}

		if (!preg_match(self::PATTERN, $value, $match)) {
			return;
		}

		$line = $token->getLine();
		if ($this->earlierMatchOnLine($tokenIndex, $tokens, $line)) {
			return;
		}

		$this->addWarning(
			sprintf(
				'Avoid arbitrary `%s-[%spx]`; use the rem scale (%s) — see tailwindcss.md § Unit Doctrine',
				$match[1],
				$match[2],
				$this->suggestRemScale($match[1], $match[2]),
			),
			$token,
			'ArbitraryPx',
		);
	}

	private function earlierMatchOnLine(int $tokenIndex, Tokens $tokens, int $line): bool
	{
		for ($i = $tokenIndex - 1; $i >= 0; --$i) {
			$neighbor = $tokens->get($i);
			if ($neighbor->getLine() !== $line) {
				break;
			}
			if (!$this->isScannable($neighbor)) {
				continue;
			}
			$neighborValue = $neighbor->getValue();
			if (false === strpos($neighborValue, 'px]')) {
				continue;
			}
			if (preg_match(self::PATTERN, $neighborValue)) {
				return true;
			}
		}

		return false;
	}

	private function isScannable(Token $token): bool
	{
		$type = $token->getType();

		return Token::TEXT_TYPE === $type || Token::STRING_TYPE === $type;
	}

	/**
	 * Suggest the nearest rem-scale utility for a given px value.
	 *
	 * Tailwind v4 spacing scale: 1 step = 0.25rem = 4px (default root). The
	 * scale accepts decimals at quarter boundaries (`mt-0.5`, `mt-1.5`,
	 * `mt-2.75`) for everyday spacing; integer values for larger sizes.
	 * Tailwind v4 also accepts arbitrary integer values like `mt-37` via the
	 * `--spacing` token, so any clean integer is valid.
	 *
	 * Negative px → Tailwind's `-prefix-N` convention (e.g., `-mt-3`), not
	 * `prefix--N`.
	 *
	 * Non-quarter fractional values (≠ multiples of 0.25) don't map to a
	 * single utility — suggest the nearest pair of integers instead so the
	 * designer can pick which side of the visual rhythm wins.
	 */
	private function suggestRemScale(string $prefix, string $pxValue): string
	{
		$n = (float) $pxValue;
		$negative = $n < 0;
		$absStep = abs($n) / 4.0;
		$prefixed = $negative ? '-' . $prefix : $prefix;

		// Tailwind v4 ships decimal steps (.25/.5/.75) only up to ~5.5; above
		// that, the generator emits integers (via the `--spacing` token).
		// Suggesting `size-30.25` would be misleading — there's no such class.
		$DECIMAL_CEILING = 6;
		$quartered = $absStep * 4;
		$isQuarterBoundary = abs($quartered - round($quartered)) < 0.001;

		if ($isQuarterBoundary && $absStep < $DECIMAL_CEILING) {
			$value = round($quartered) / 4;
			$formatted = abs($value - (int) $value) < 0.001
				? (string) (int) $value
				: rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');

			return sprintf('e.g., `%s-%s`', $prefixed, $formatted);
		}

		// Above the decimal ceiling — only integers exist; if px divides
		// cleanly to a whole step, suggest that integer.
		if (abs($absStep - round($absStep)) < 0.001) {
			return sprintf('e.g., `%s-%d`', $prefixed, (int) round($absStep));
		}

		// Off-grid value — suggest a range of integers.
		$low = (int) floor($absStep);
		$high = (int) ceil($absStep);

		return sprintf('e.g., `%s-%d` or `%s-%d`', $prefixed, $low, $prefixed, $high);
	}
}
