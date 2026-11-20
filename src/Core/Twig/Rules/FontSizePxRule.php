<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a font size or a line height is an arbitrary `[Npx]` value.
 *
 * Doctrine: `.claude/rules/theme/tailwindcss.md` § Pixel exceptions in
 * `tailwind-base`. A px font size ignores the reader's default font size;
 * rem follows it. A size off the scale is a project `--text-*` token or
 * arbitrary rem (`text-[3.125rem]` for 50 px, px / 16). A line height is
 * unitless or rem: a px line height under a rem font size makes the lines
 * overlap once the reader enlarges the text.
 *
 * Scope:
 *  - `text-[Npx]` whose value has no named Tailwind size. A value with one
 *    (`text-[16px]` → `text-base`) is left to `ScaleValueRule`, so one class
 *    gets one warning.
 *  - `leading-[Npx]`, every value.
 *  - Variant prefixes still trigger (`lg:text-[50px]`).
 *  - Raw HTML class attributes (TEXT_TYPE) and Twig string literals in
 *    `classes:` arrays and `create_attribute()` calls (STRING_TYPE).
 *
 * Out of scope: `text-[Nrem]`, `text-[clamp(…)]`, `text-[var(…)]`, colours
 * (`text-[#fff]`), `tracking-[Npx]` (letter spacing does not follow the
 * reader's font size setting in a way that breaks layout).
 *
 * A heading with `text-[Npx]` also gets a `HeadingArbitraryFontSize`
 * warning. The two say different things: that rule asks for a family
 * utility, this one for rem.
 *
 * Per-line dedupe: one warning per line, like `ArbitraryPxRule`.
 */
final class FontSizePxRule extends AbstractRule
{
	/** Font sizes with a named Tailwind utility; `ScaleValueRule` reports these. */
	private const NAMED_TEXT_PX = [12, 14, 16, 18, 20, 24, 30, 36, 48, 60, 72, 96, 128];

	/**
	 * Group 1: `text` or `leading`. Group 2: the px value.
	 *
	 * The lookbehind blocks compound identifiers (`my-text-[1px]`) and
	 * allows variant prefixes (`lg:text-[1px]`), as in `ArbitraryPxRule`.
	 */
	private const PATTERN = '/(?<![a-zA-Z0-9_-])(text|leading)-\[(\d+(?:\.\d+)?)px\]/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (!$this->isScannable($token)) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, 'px]')) {
			return;
		}

		$match = $this->firstReportable($value);
		if (null === $match) {
			return;
		}

		if ($this->earlierMatchOnLine($tokenIndex, $tokens, $token->getLine())) {
			return;
		}

		[$prefix, $px] = $match;
		$this->addWarning(
			sprintf(
				'Avoid `%s-[%spx]`; use rem (`%s-[%srem]`) or a token — px ignores the reader\'s font size, see tailwindcss.md § Pixel exceptions',
				$prefix,
				$px,
				$prefix,
				$this->toRem($px),
			),
			$token,
			'FontSizePx',
		);
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private function firstReportable(string $value): ?array
	{
		if (!preg_match_all(self::PATTERN, $value, $matches, \PREG_SET_ORDER)) {
			return null;
		}

		foreach ($matches as $match) {
			if ('text' === $match[1] && \in_array((float) $match[2], array_map('floatval', self::NAMED_TEXT_PX), true)) {
				continue;
			}

			return [$match[1], $match[2]];
		}

		return null;
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
			if (null !== $this->firstReportable($neighbor->getValue())) {
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

	private function toRem(string $px): string
	{
		return rtrim(rtrim(sprintf('%.5f', (float) $px / 16), '0'), '.');
	}
}
