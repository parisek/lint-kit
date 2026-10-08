<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an `<h1>`–`<h6>` element carries an arbitrary `text-[Nunit]`
 * font-size utility instead of a fluid family utility.
 *
 * Project convention (see `.claude/rules/typography.md` § Family × Size
 * Taxonomy): every heading must pick from the fluid named utilities
 * `text-display-*` / `text-heading-*` / `text-title-*`. These are tuned via
 * `clamp()` and follow the project's responsive type scale; arbitrary px /
 * rem / em values bypass the scale, freeze the size at every viewport, and
 * break visual rhythm against the rest of the design system.
 *
 * Detection (token-level, forward-walk from heading opener):
 *  1. Scan each `TEXT_TYPE` token for `<h[1-6]\b` element-opener literals.
 *     The tokenizer doesn't preserve element boundaries, so we have to
 *     reconstruct them ourselves.
 *  2. From each match, walk **forward** through whitespace / EOL / tab and
 *     subsequent TEXT_TYPE tokens, concatenating their values, until we
 *     find the `>` that closes the opening tag. The reconstructed string
 *     is the full opening tag (`<h2 class="mt-3 font-semibold text-[2.5rem] text-balance"`).
 *     Class attributes whose value contains whitespace — by far the
 *     dominant real-world case — span multiple tokens; this is the only
 *     reliable way to see them whole.
 *  3. Run `text-\[\d+(?:\.\d+)?(?:px|rem|em)\]` over the reconstructed
 *     opener. Same length-unit restriction as ScaleValueRule, so we don't
 *     false-positive on color forms (`text-[#ff0000]`, `text-[var(--brand)]`).
 *  4. Variant prefixes (`lg:text-[40px]`, `hover:text-[2rem]`) still fire —
 *     the underlying utility is still arbitrary.
 *
 * Known limitations (deliberate):
 *  - Class names assembled from Twig variables (`<h1 class="{{ classes }}">`
 *    where `classes` happens to contain `text-[40px]`) are not flagged —
 *    the rule scans literal token text, not evaluated runtime values.
 *  - Headings whose opening tag is interrupted by a Twig expression
 *    (`<h1 {% if x %}class="text-[40px]"{% endif %}>`) are skipped — the
 *    forward walk bails on the first non-TEXT/whitespace token, because
 *    we can't know which branch will run. The surrounding `{% if %}` is
 *    usually a structural smell anyway.
 *  - Arbitrary forms with non-length units (`text-[clamp(1rem,2vw,3rem)]`,
 *    `text-[var(--size)]`) are skipped — by the time you're writing
 *    `clamp()` inline, you should be promoting it to a `--text-*` token.
 */
final class HeadingArbitraryFontSizeRule extends AbstractRule
{
	/**
	 * Match `<h1>`–`<h6>` opener literals. `\b` after the digit prevents
	 * `<h1class="foo">` (invalid markup, but cheap to guard) and matches the
	 * common `<h1>`, `<h1 …>`, `<h1\nclass="…"` shapes.
	 *
	 * Group 1: heading level (1–6).
	 *
	 * Used with `PREG_OFFSET_CAPTURE` so we can slice the opener out of the
	 * containing token text.
	 */
	private const HEADING_OPEN_PATTERN = '/<h([1-6])\b/i';

	/**
	 * Match `text-[Nunit]` where unit is `px`, `rem`, or `em`.
	 *
	 * Lookbehind `(?<![a-zA-Z0-9_-])` blocks suffix matches inside compound
	 * identifiers (`mytext-[40px]`, `some-text-[40px]`), while still allowing
	 * variant prefixes (`lg:text-[40px]`, `before:text-[40px]`) because `:`
	 * is not in the negation class.
	 */
	private const ARBITRARY_FONT_SIZE_PATTERN = '/(?<![a-zA-Z0-9_-])text-\[\d+(?:\.\d+)?(?:px|rem|em)\]/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();
		if (!preg_match_all(self::HEADING_OPEN_PATTERN, $value, $headMatches, PREG_OFFSET_CAPTURE)) {
			return;
		}

		foreach ($headMatches[0] as $idx => $match) {
			$startPos = $match[1];
			$level = $headMatches[1][$idx][0];

			$opener = $this->collectOpener($tokenIndex, $tokens, $value, $startPos);
			if (null === $opener) {
				continue;
			}

			if (!preg_match_all(self::ARBITRARY_FONT_SIZE_PATTERN, $opener, $sizes, PREG_SET_ORDER)) {
				continue;
			}

			foreach ($sizes as $size) {
				$this->addWarning(
					sprintf(
						'<h%s> uses arbitrary font size %s; use a fluid family utility (text-display-*, text-heading-*, text-title-*) — see typography.md § Family × Size Taxonomy',
						$level,
						$size[0],
					),
					$token,
					'HeadingArbitraryFontSize',
				);
			}
		}
	}

	/**
	 * Reconstruct the full opening-tag text starting at `<h[1-6]` in the
	 * current token, walking forward across whitespace and subsequent text
	 * tokens until the first `>`.
	 *
	 * Returns the opener text from `<` up to (but excluding) `>`, or `null`
	 * if the tag is interrupted by a Twig expression or never closes.
	 */
	private function collectOpener(int $tokenIndex, Tokens $tokens, string $startValue, int $startPos): ?string
	{
		$tail = substr($startValue, $startPos);
		$closeIdx = strpos($tail, '>');
		if (false !== $closeIdx) {
			return substr($tail, 0, $closeIdx);
		}

		$accumulated = $tail;
		$total = count($tokens->toArray());

		for ($i = $tokenIndex + 1; $i < $total; ++$i) {
			$next = $tokens->get($i);
			$type = $next->getType();

			if (
				Token::WHITESPACE_TYPE === $type
				|| Token::EOL_TYPE === $type
				|| Token::TAB_TYPE === $type
			) {
				$accumulated .= $next->getValue();
				continue;
			}

			if (Token::TEXT_TYPE !== $type) {
				return null;
			}

			$nextValue = $next->getValue();
			$closeIdx = strpos($nextValue, '>');
			if (false !== $closeIdx) {
				return $accumulated . substr($nextValue, 0, $closeIdx);
			}

			$accumulated .= $nextValue;
		}

		return null;
	}
}
