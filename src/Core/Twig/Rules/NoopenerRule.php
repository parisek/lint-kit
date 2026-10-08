<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when `target="_blank"` is used without `rel="noopener noreferrer"`.
 *
 * Mirrors the Python `missing-noopener` lint: looks for the literal
 * `target=["']?_blank` pattern and confirms `noopener` appears within
 * a ±3-line context window.
 *
 * Two scan paths cover real-world templates:
 *  - **STRING_TYPE**: Twig string literals (e.g. `component_content({
 *    html: '<a target="_blank">…</a>' })`) carry the entire HTML snippet
 *    as one token value, so noopener can be checked inside the same value.
 *  - **TEXT_TYPE**: raw HTML — twig-cs-fixer's tokenizer splits text on
 *    whitespace, so `target="_blank"` and `rel="noopener noreferrer"`
 *    land in separate tokens. Walk the surrounding tokens within ±3 lines
 *    and concatenate their text values to mimic the Python line window.
 *
 * `<area target="_blank">` is also caught because the regex matches any
 * occurrence of `target=…_blank`, regardless of the preceding tag name.
 */
final class NoopenerRule extends AbstractRule
{
	private const TARGET_BLANK_PATTERN = '/target\s*=\s*["\']?\s*_blank/i';

	private const CONTEXT_LINES_BEFORE = 3;

	private const CONTEXT_LINES_AFTER = 2;

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		$type = $token->getType();

		if (Token::TEXT_TYPE !== $type && Token::STRING_TYPE !== $type) {
			return;
		}

		$value = $token->getValue();
		if (1 !== preg_match(self::TARGET_BLANK_PATTERN, $value)) {
			return;
		}

		if (Token::STRING_TYPE === $type) {
			// String literal carries the whole HTML snippet — same-value check suffices.
			if (false === stripos($value, 'noopener')) {
				$this->fire($token);
			}

			return;
		}

		// TEXT_TYPE: gather neighboring text tokens within the ±3-line window.
		if (false !== stripos($this->collectContext($tokenIndex, $tokens, $token->getLine()), 'noopener')) {
			return;
		}

		$this->fire($token);
	}

	private function collectContext(int $tokenIndex, Tokens $tokens, int $line): string
	{
		$minLine = $line - self::CONTEXT_LINES_BEFORE;
		$maxLine = $line + self::CONTEXT_LINES_AFTER;
		$context = '';

		// Walk backward.
		for ($i = $tokenIndex - 1; $i >= 0; --$i) {
			$neighbor = $tokens->get($i);
			if ($neighbor->getLine() < $minLine) {
				break;
			}
			if (Token::TEXT_TYPE === $neighbor->getType() || Token::STRING_TYPE === $neighbor->getType()) {
				$context .= ' ' . $neighbor->getValue();
			}
		}

		// Walk forward (start from current token to include its own value).
		$total = \count($tokens->toArray());
		for ($i = $tokenIndex; $i < $total; ++$i) {
			$neighbor = $tokens->get($i);
			if ($neighbor->getLine() > $maxLine) {
				break;
			}
			if (Token::TEXT_TYPE === $neighbor->getType() || Token::STRING_TYPE === $neighbor->getType()) {
				$context .= ' ' . $neighbor->getValue();
			}
		}

		return $context;
	}

	private function fire(Token $token): void
	{
		$this->addWarning(
			'target="_blank" should include rel="noopener noreferrer"',
			$token,
			'Noopener',
		);
	}
}
