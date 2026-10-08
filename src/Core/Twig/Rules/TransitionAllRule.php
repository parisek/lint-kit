<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when `transition-all` appears in a class list.
 *
 * Tailwind's `transition-all` animates *every* CSS property change, including
 * ones the developer didn't intend (a sibling component flipping `display`
 * from `none` to `flex` during a header collapse animates the implicit
 * properties; a `transition-all` on a panel with an `:has(.error)` toggle
 * animates the `display` change mid-flight). The project convention
 * (see `.claude/docs/theme/header-customization.md` § 2 — Visual Token Tweak
 * / category "Transitions") is to scope transitions explicitly:
 *
 *   class="transition-[background-color,box-shadow] duration-300"
 *
 * Property-scoped `transition-[…]` opts in deliberately — no judder from
 * unrelated property changes.
 *
 * The rule is warn-only. Some sites legitimately want "everything that can
 * animate" (e.g., a hover state that just changes `color`); the maintainer
 * should decide per-site whether scoping is worth it.
 *
 * Hybrid scan over:
 *  - **TEXT_TYPE** — raw HTML class attributes like `class="transition-all"`.
 *  - **STRING_TYPE** — Twig string literals inside `classes:` arrays /
 *    `create_attribute()` calls.
 *
 * Lookbehind `(?<![a-zA-Z0-9_-])` blocks suffix matches inside compound
 * identifiers (`my-transition-all-utility`, `pre-transition-all-foo`).
 * Lookahead `(?![a-zA-Z0-9_-])` blocks prefix matches that would extend
 * the utility (`transition-all-fast` doesn't exist in Tailwind, but the
 * lookahead future-proofs against project-local custom utilities like
 * `transition-allowed` — which a project might genuinely define).
 *
 * Variant prefixes trigger (`hover:transition-all`, `lg:transition-all`) —
 * the underlying utility is still `transition-all`. Per-line dedupe means a
 * single line with the utility twice fires once.
 */
final class TransitionAllRule extends AbstractRule
{
	private const PATTERN = '/(?<![a-zA-Z0-9_-])transition-all(?![a-zA-Z0-9_-])/';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (!$this->isScannable($token)) {
			return;
		}

		$value = $token->getValue();
		if (false === strpos($value, 'transition-all')) {
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
			'Avoid transition-all; scope with transition-[<prop>,<prop>] to prevent unrelated property changes from animating (see header-customization.md § 2)',
			$token,
			'TransitionAll',
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
			if (false === strpos($neighborValue, 'transition-all')) {
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
