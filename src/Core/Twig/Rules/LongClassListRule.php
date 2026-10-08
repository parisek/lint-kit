<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an HTML `class="…"` literal exceeds a character threshold,
 * suggesting a refactor to `create_attribute({ class: [...] })`.
 *
 * Why: long single-line class lists are hard to scan, hard to diff, and
 * hard to keep tidy under Tailwind variant prefixes. The project ships a
 * `create_attribute` helper (see `.claude/docs/theme/twig-patterns.md`
 * § HTML Attributes with create_attribute) that takes an array of class
 * names and emits a cleanly-formatted attribute. Above ~120 characters the
 * array form is almost always the more readable choice.
 *
 * Detection (token-level, forward-walk from `class=` opener):
 *  1. Scan each `TEXT_TYPE` token for `class="` / `class='` literals — the
 *     attribute opener.
 *  2. From each match, walk **forward** through whitespace / EOL / tab and
 *     subsequent TEXT_TYPE tokens, concatenating their values, until we
 *     find the matching closing quote. Class attributes whose value
 *     contains whitespace span multiple tokens; this is the only reliable
 *     way to see them whole.
 *  3. If the reconstructed value contains `{{` or `{%` it's a Twig-
 *     interpolated class — skipped, because the literal length is not
 *     what's actually rendered.
 *  4. If `strlen($value) > $maxLength`, fire a warning.
 *
 * Configurable: `$maxLength` constructor argument (default 120). Lower the
 * limit per-project by passing a different value in `config.php`, or
 * disable per-line / per-region using the standard
 * `{# twig-cs-fixer-disable-next-line LongClassList #}` doctrine.
 *
 * Known limitations (deliberate):
 *  - Class names assembled from Twig variables are not flagged — the rule
 *    scans literal token text, not evaluated runtime values.
 *  - Class attributes interrupted by a Twig expression
 *    (`class="foo {% if x %}bar{% endif %} baz"`) are skipped — the forward
 *    walk bails on the first non-TEXT/whitespace token.
 *  - Quote-mismatched attributes (`class="…'`) are skipped silently — the
 *    walk only stops on the same quote character it opened with.
 */
final class LongClassListRule extends AbstractRule
{
	/**
	 * Match `class="` or `class='` openers. Group 1 captures the opening
	 * quote so we know which character to look for as the closer.
	 *
	 * `\b` before `class` prevents suffix matches inside compound names
	 * (`subclass="…"`, `superclass="…"`). The `(?<!:)` lookbehind excludes
	 * Alpine/Vue dynamic bindings (`:class="{…}"`, `x-bind:class="…"`) —
	 * their value is a JS object literal, not a static class list, so the
	 * create_attribute refactor this rule suggests does not apply there.
	 * Back-promoted from fellows, where two availability-dot bindings
	 * needed an identical Layer A disable.
	 */
	private const CLASS_OPEN_PATTERN = '/(?<!:)\bclass=(["\'])/';

	private const DEFAULT_MAX_LENGTH = 120;

	public function __construct(private readonly int $maxLength = self::DEFAULT_MAX_LENGTH)
	{
	}

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();
		if (!preg_match_all(self::CLASS_OPEN_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE)) {
			return;
		}

		foreach ($matches[0] as $idx => $match) {
			$openerStart = $match[1];
			$quote = $matches[1][$idx][0];
			$valueStart = $openerStart + strlen($match[0]);

			$classValue = $this->collectClassValue($tokenIndex, $tokens, $value, $valueStart, $quote);
			if (null === $classValue) {
				continue;
			}

			if (str_contains($classValue, '{{') || str_contains($classValue, '{%')) {
				continue;
			}

			$length = strlen($classValue);
			if ($length <= $this->maxLength) {
				continue;
			}

			$this->addWarning(
				sprintf(
					'class attribute is %d chars long (limit %d) — refactor with create_attribute({ class: [...] }) — see twig-patterns.md § HTML Attributes with create_attribute',
					$length,
					$this->maxLength,
				),
				$token,
				'LongClassList',
			);
		}
	}

	/**
	 * Reconstruct the class-attribute value starting at `$valueStart` in
	 * the current token, walking forward across whitespace and subsequent
	 * text tokens until the matching closing quote.
	 *
	 * Returns the value text (excluding both quotes), or `null` if the
	 * attribute is interrupted by a Twig expression or never closes.
	 */
	private function collectClassValue(
		int $tokenIndex,
		Tokens $tokens,
		string $startValue,
		int $valueStart,
		string $quote,
	): ?string {
		$tail = substr($startValue, $valueStart);
		$closeIdx = strpos($tail, $quote);
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
			$closeIdx = strpos($nextValue, $quote);
			if (false !== $closeIdx) {
				return $accumulated . substr($nextValue, 0, $closeIdx);
			}

			$accumulated .= $nextValue;
		}

		return null;
	}
}
