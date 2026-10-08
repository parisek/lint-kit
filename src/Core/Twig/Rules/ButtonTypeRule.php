<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a `<button>` element has no explicit `type=` attribute.
 *
 * The HTML spec defaults `<button>` to `type="submit"` when it sits inside a
 * `<form>` — a surprising default that has caused real bugs in the codebase
 * (clicking a "Cancel" button submitting the form). Always declaring the
 * type makes the intent explicit and survives form-context refactors.
 *
 * Token-level scan over `TEXT_TYPE`: locate every `<button` opener, gather a
 * forward context window (text + string + interpolation tokens) up to the
 * first standalone `>` that closes the opening tag, then verify the span
 * contains either:
 *  - a literal `type=` attribute (any quoting, any spacing around `=`), or
 *  - any `{{ <var> }}` interpolation inside the opening tag. The project
 *    idiom for `<button{{ … }}>` is always `create_attribute({ type: '…', … })`
 *    output — the rule trusts that idiom rather than enforcing a specific
 *    variable name. A button with an unrelated interpolation in its opening
 *    tag (rare in practice) is accepted as a false negative.
 *
 * If neither marker appears in the opening tag span, fire a warning. The
 * rule does not attempt to auto-fix because the correct value (`button` vs
 * `submit` vs `reset`) is intent-dependent.
 *
 * Edge cases:
 *  - `<input type="button">` is not affected (only `<button>` matches).
 *  - `<button>` appearing inside a Twig comment is ignored — comment tokens
 *    are different types.
 *  - The substring `<button` followed by an alphanumeric / dash / underscore
 *    (e.g. inside a hypothetical custom element `<button-like>`) is rejected
 *    by a trailing character check.
 */
final class ButtonTypeRule extends AbstractRule
{
	private const TAG_PATTERN = '/<button(?![a-zA-Z0-9_-])/';

	private const TYPE_ATTR_PATTERN = '/\btype\s*=/i';

	private const MACRO_PATTERN = '/\{\{\s*\w+\s*\}\}/';

	private const CONTEXT_LINES_AFTER = 6;

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, '<button')) {
			return;
		}

		if (!preg_match_all(self::TAG_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE)) {
			return;
		}

		foreach ($matches[0] as $match) {
			$matchOffset = $match[1];
			$openingTagSpan = $this->collectOpeningTagSpan(
				$value,
				$matchOffset,
				$tokenIndex,
				$tokens,
				$token->getLine(),
			);

			if ($this->hasTypeOrMacro($openingTagSpan)) {
				continue;
			}

			$this->addWarning(
				'<button> missing explicit type= (defaults to "submit" inside a form)',
				$token,
				'ButtonType',
			);
		}
	}

	/**
	 * Build the text of the `<button … >` opening tag, possibly spanning
	 * neighboring tokens for multi-line buttons or `{{ var }}` interpolations.
	 */
	private function collectOpeningTagSpan(
		string $tokenValue,
		int $matchOffset,
		int $tokenIndex,
		Tokens $tokens,
		int $line,
	): string {
		// Start with the slice from `<button` to end-of-token.
		$tail = substr($tokenValue, $matchOffset);
		$gtPos = strpos($tail, '>');
		if (false !== $gtPos) {
			return substr($tail, 0, $gtPos);
		}

		// Tag spans multiple tokens — gather forward neighbors within window.
		$span = $tail;
		$maxLine = $line + self::CONTEXT_LINES_AFTER;
		$total = \count($tokens->toArray());

		for ($i = $tokenIndex + 1; $i < $total; ++$i) {
			$neighbor = $tokens->get($i);
			if ($neighbor->getLine() > $maxLine) {
				break;
			}
			$neighborValue = $neighbor->getValue();
			$gtPos = strpos($neighborValue, '>');
			if (false !== $gtPos) {
				$span .= ' ' . substr($neighborValue, 0, $gtPos);

				return $span;
			}
			$span .= ' ' . $neighborValue;
		}

		return $span;
	}

	private function hasTypeOrMacro(string $span): bool
	{
		if (preg_match(self::TYPE_ATTR_PATTERN, $span)) {
			return true;
		}

		return 1 === preg_match(self::MACRO_PATTERN, $span);
	}
}
