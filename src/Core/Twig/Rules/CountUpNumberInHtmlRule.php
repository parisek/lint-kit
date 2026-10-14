<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a `gsap-animation="count-up"` element has no number in its source text.
 *
 * `count-up` writes the number at runtime. An element that ships the text `0` (or
 * nothing) shows `0` to every client that does not run the animation: crawlers,
 * LLM fetchers, a page with JavaScript off. The end value must be in the HTML.
 *
 * Doctrine: `gsap.md` § `count-up` in `tailwind-base` (portadesign/tailwind-base#920).
 *
 * What counts as a number in the source:
 *  - **Twig output** (`{{ … }}`) or a Twig tag inside the element. The rule cannot
 *    evaluate it, so it trusts it.
 *  - **A literal** that differs from the start value (`gsap-start`, default `0`).
 *
 * What fires:
 *  - An empty element.
 *  - A literal with no digit.
 *  - A literal equal to the start value, `0` by default.
 *
 * The scan works on the whole template, so an opening tag that holds Twig output
 * inside its attributes (`gsap-end="{{ n }}"`) is still one tag. Twig comments are
 * ignored. `create_attribute()` payloads are not scanned: the element body is not
 * in the same file as the attribute map there.
 */
final class CountUpNumberInHtmlRule extends AbstractRule
{
	private const PLACEHOLDER = "\x00";

	private const TAG = '/<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		// One scan for the whole template: an element spans many tokens.
		if (0 !== $tokenIndex) {
			return;
		}

		[$flat, $starts] = $this->flatten($tokens);
		$flat = $this->mask($flat);
		if (false === strpos($flat, 'count-up')) {
			return;
		}

		if (!preg_match_all(self::TAG, $flat, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			return;
		}

		foreach ($matches as $match) {
			$attributes = $match[2][0];
			if (!preg_match('/gsap-animation\s*=\s*(["\']?)count-up\1(?=[\s\/>]|$)/', $attributes)) {
				continue;
			}

			$tag = strtolower($match[1][0]);
			$contentStart = $match[0][1] + strlen($match[0][0]);
			$inner = $this->innerHtml($flat, $tag, $contentStart);
			// Nested tags are markup, not the number: strip them, so their attributes add no digit.
			// Explicit characters in trim(): the default set strips the NUL placeholder.
			$content = trim(preg_replace('/<[^>]*>/', ' ', $inner) ?? $inner, " \t\r\n");

			if (false !== strpos($content, self::PLACEHOLDER)) {
				continue;
			}

			$start = '0';
			if (preg_match('/gsap-start\s*=\s*(["\'])([^"\']*)\1/', $attributes, $startMatch)) {
				$start = $startMatch[2];
			}

			$problem = $this->problem($content, $start);
			if (null === $problem) {
				continue;
			}

			$this->addWarning(
				$problem . ' Print the end value (and suffix) in the element text; the animation only counts up to it (see gsap.md § count-up).',
				$tokens->get($this->tokenAt($starts, $match[0][1])),
				'CountUpNumberInHtml',
			);
		}
	}

	private function problem(string $content, string $start): ?string
	{
		if ('' === $content) {
			return 'The count-up element is empty, so a client without the animation reads no number.';
		}

		if (!preg_match('/\d/', $content)) {
			return 'The count-up element has no number in its text.';
		}

		$printed = $this->firstNumber($content);
		$startNumber = $this->firstNumber($start) ?? 0.0;
		if (null !== $printed && $printed === $startNumber) {
			return sprintf('The count-up element prints the start value (%s), so a client without the animation reads it as the number.', $start);
		}

		return null;
	}

	/** The first number in the text as a float, or null. `1 000`, `1,5` and `1.5` are read as numbers. */
	private function firstNumber(string $text): ?float
	{
		$text = str_replace(["\u{00A0}", ' '], '', $text);
		if (!preg_match('/-?\d+(?:[.,]\d+)?/', $text, $m)) {
			return null;
		}

		return (float) str_replace(',', '.', $m[0]);
	}

	/** Replaces HTML comments and the bodies of script and style with spaces, so offsets stay valid. */
	private function mask(string $flat): string
	{
		$blank = static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

		return (string) preg_replace_callback(
			'/<!--.*?-->|<(script|style)\b[^>]*>.*?<\/\1\s*>/is',
			$blank,
			$flat,
		);
	}

	/** The markup between the opening tag and its matching close tag; nested tags of the same name are counted. */
	private function innerHtml(string $flat, string $tag, int $from): string
	{
		$depth = 1;
		$pattern = '/<(\/?)' . preg_quote($tag, '/') . '(?=[\s\/>])[^>]*>/i';
		$offset = $from;
		while (preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE, $offset)) {
			$depth += '' === $m[1][0] ? 1 : -1;
			if (0 === $depth) {
				return substr($flat, $from, $m[0][1] - $from);
			}
			$offset = $m[0][1] + strlen($m[0][0]);
		}

		return substr($flat, $from);
	}

	/** Whether the block tag at $index is `raw` or `verbatim`: its body is literal text, not Twig output. */
	private function isLiteralBlock(Tokens $tokens, int $index): bool
	{
		for ($i = $index + 1, $max = count($tokens->toArray()); $i < $max; ++$i) {
			$token = $tokens->get($i);
			if (in_array($token->getType(), [Token::WHITESPACE_TYPE, Token::TAB_TYPE, Token::EOL_TYPE], true)) {
				continue;
			}

			return in_array($token->getValue(), ['raw', 'endraw', 'verbatim', 'endverbatim'], true);
		}

		return false;
	}

	/**
	 * @return array{0: string, 1: list<array{0: int, 1: int}>} Flat text, and [offset, token index] per token.
	 */
	private function flatten(Tokens $tokens): array
	{
		$flat = '';
		$starts = [];
		foreach ($tokens->toArray() as $index => $token) {
			$starts[] = [strlen($flat), $index];
			$type = $token->getType();
			if (Token::TEXT_TYPE === $type) {
				$flat .= $token->getValue();
			} elseif (Token::WHITESPACE_TYPE === $type || Token::TAB_TYPE === $type || Token::EOL_TYPE === $type) {
				// The lexer splits whitespace out of the text; it still separates attributes.
				$flat .= $token->getValue();
			} elseif (Token::VAR_START_TYPE === $type) {
				$flat .= self::PLACEHOLDER;
			} elseif (Token::BLOCK_START_TYPE === $type && !$this->isLiteralBlock($tokens, $index)) {
				$flat .= self::PLACEHOLDER;
			}
		}

		return [$flat, $starts];
	}

	/**
	 * @param list<array{0: int, 1: int}> $starts
	 */
	private function tokenAt(array $starts, int $offset): int
	{
		$found = 0;
		foreach ($starts as [$start, $index]) {
			if ($start > $offset) {
				break;
			}
			$found = $index;
		}

		return $found;
	}
}
