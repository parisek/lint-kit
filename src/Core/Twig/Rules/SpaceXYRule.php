<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when `space-x-*` or `space-y-*` utilities appear in class lists.
 *
 * Tailwind's `space-x` / `space-y` utilities apply margin to every child
 * except the first via the `:not(:first-child)` selector. They have known
 * edge cases (RTL bugs with `space-x`, fights with margin collapsing,
 * surprising behavior when children are conditionally rendered) and v4
 * generally favors flex/grid parents with `gap-*`, which is layout-agnostic
 * and behaves correctly across writing modes.
 *
 * The rule is warn-only: auto-renaming is unsafe because `gap-*` requires
 * the parent to be `flex` or `grid` (block-level children with `space-y-*`
 * would visually break under a naive substitution). The maintainer should
 * decide whether a flex/grid refactor is reasonable for each call site.
 *
 * Hybrid scan over:
 *  - **TEXT_TYPE** — raw HTML class attributes like `class="space-y-4"`.
 *  - **STRING_TYPE** — Twig string literals carrying class fragments inside
 *    `classes:` arrays / `create_attribute()` calls.
 *
 * The regex requires a leading word boundary so that lookalike substrings
 * (`namespace-x-foo`, `aerospace-x-1`, `my-space-y-helper`) do not trigger.
 * Variant prefixes (`hover:space-x-2`, `lg:space-y-4`) trigger because the
 * underlying utility is still `space-y-4`. Per-line dedupe means a single
 * line with multiple matches (`class="space-x-2 space-y-4"`) fires once.
 */
final class SpaceXYRule extends AbstractRule
{
	private const PATTERN = '/(?<![a-zA-Z0-9_-])space-[xy]-/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (!$this->isScannable($token)) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, 'space-')) {
			return;
		}

		if (!preg_match(self::PATTERN, $value)) {
			return;
		}

		$line = $token->getLine();
		if ($this->earlierMatchOnLine($tokenIndex, $tokens, $line)) {
			return;
		}

		$this->addWarning(
			'Avoid space-x-*/space-y-*; prefer flex/grid parent with gap-* (handles RTL + last-child correctly)',
			$token,
			'SpaceXY',
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
			if (false === strpos($neighborValue, 'space-')) {
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
}
