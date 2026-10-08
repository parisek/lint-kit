<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an inline ternary expression is interpolated directly into
 * a raw HTML `class="…"` attribute.
 *
 * Project doctrine (see `.claude/docs/theme/create-attribute.md` § "When
 * to Use `create_attribute`"): any conditional class composition belongs
 * inside `create_attribute({ class: [...] })`, not glued into the
 * attribute string. The fix is mechanical:
 *
 *     {# ❌ Wrong — inline ternary in raw class attribute #}
 *     <div class="{{ content.dark_background ? 'prose prose-invert' : 'prose' }}">
 *
 *     {# ✅ Right — composition lives in create_attribute #}
 *     {% set body_attributes = create_attribute({
 *         class: [
 *             'prose max-w-none',
 *             content.dark_background ? 'prose-invert' : '',
 *         ],
 *     }) %}
 *     <div {{ body_attributes }}>
 *
 * Detection (token-level, scoped to `class="…"`):
 *  1. Scan each `TEXT_TYPE` token for `class="` / `class='` openers — same
 *     anchor mechanism as `LongClassListRule`.
 *  2. From each match, walk **forward** through tokens, tracking whether
 *     we're currently inside a `{{ … }}` print block.
 *  3. Inside a print block, watch for operator tokens whose value is
 *     `'?'` (full ternary `x ? a : b` — `TERNARY_OPERATOR_TYPE` since
 *     twig-cs-fixer 4.0) or `'?:'` (short ternary `x ?: a` — stays
 *     `OPERATOR_TYPE`). Fire one warning per match — at the operator
 *     token, so the line/column points at the offending site.
 *  4. Stop walking when, outside any `{{ … }}` block, a `TEXT_TYPE` token
 *     contains the matching closing quote.
 *
 * Skipped silently:
 *  - `??` (null-coalescing) — tokenized as a single `OPERATOR_TYPE` with
 *    value `'??'`, distinct from `?` / `?:`. Null-coalescing is a single
 *    source with a fallback, doctrine-acceptable inline.
 *  - Bare variables (`{{ var }}`), filter chains, function calls — no
 *    `?` operator token, no fire.
 *  - Ternaries in non-class attributes (`data-foo="{{ x ? 'a' : 'b' }}"`)
 *    — out of scope; the rule's anchor is `class="`.
 *  - Ternaries outside attribute context (`{% if x ? 'a' : 'b' %}`) —
 *    out of scope; same reason.
 *  - Method calls returning ternaries (`class="{{ helper() }}"` where
 *    `helper()` internally returns a ternary) — invisible at the call
 *    site, so not flagged. The smell is the *visible* inline ternary.
 *
 * Known limitations:
 *  - Class-attribute scope only. Ternaries in `data-*`, `aria-*`,
 *    `title`, etc. don't fire — that's a different concern (rule 2 covers
 *    its own data-* doctrine).
 *  - Single-element ternary inside `create_attribute({ class: [x ? 'a' : ''] })`
 *    is allowed — the rule's `class="` anchor doesn't match the `class:`
 *    hash key, so the array form is unaffected.
 */
final class InlineConditionalClassRule extends AbstractRule
{
	/**
	 * Match `class="` or `class='` openers. Group 1 captures the opening
	 * quote so we know which character to look for as the closer.
	 *
	 * `\b` before `class` prevents suffix matches inside compound names
	 * (`subclass="…"`, `superclass="…"`).
	 */
	private const CLASS_OPEN_PATTERN = '/\bclass=(["\'])/';

	private const TERNARY_OPERATORS = ['?', '?:'];

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
			$quote = $matches[1][$idx][0];
			$valueStart = $match[1] + \strlen($match[0]);

			// Check if attribute closes within this same token — fully literal,
			// no Twig possible, no ternaries possible.
			$tail = substr($value, $valueStart);
			if (false !== strpos($tail, $quote)) {
				continue;
			}

			$this->scanForwardForTernaries($tokenIndex, $tokens, $quote);
		}
	}

	/**
	 * Walk tokens forward from `$tokenIndex`, tracking whether we're inside
	 * a `{{ … }}` print block. Fire a warning for each ternary operator
	 * (`?` or `?:`) encountered inside a print block. Stop when, outside
	 * any print block, a TEXT_TYPE token contains the matching closing
	 * `$quote`.
	 */
	private function scanForwardForTernaries(int $tokenIndex, Tokens $tokens, string $quote): void
	{
		$total = \count($tokens->toArray());
		$insidePrint = false;

		for ($i = $tokenIndex + 1; $i < $total; ++$i) {
			$next = $tokens->get($i);
			$type = $next->getType();

			if (Token::VAR_START_TYPE === $type) {
				$insidePrint = true;
				continue;
			}

			if (Token::VAR_END_TYPE === $type) {
				$insidePrint = false;
				continue;
			}

			if ($insidePrint) {
				// twig-cs-fixer 4.0 split the operator token type: `?` is now
				// TERNARY_OPERATOR_TYPE while `?:` (Elvis) stays OPERATOR_TYPE.
				if ((Token::OPERATOR_TYPE === $type || Token::TERNARY_OPERATOR_TYPE === $type)
					&& \in_array($next->getValue(), self::TERNARY_OPERATORS, true)
				) {
					$this->addWarning(
						'Inline ternary in class attribute — extract to create_attribute({ class: [...] }) per create-attribute.md doctrine',
						$next,
						'InlineConditionalClass',
					);
				}
				continue;
			}

			if (Token::TEXT_TYPE === $type && false !== strpos($next->getValue(), $quote)) {
				return;
			}
		}
	}
}
