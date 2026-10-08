<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a template combines `loop.index` (used as a DOM identifier
 * fragment) with DOM identifier attributes (`id`, `aria-controls`,
 * `aria-labelledby`, …) but does NOT call `uniqueId()`.
 *
 * Project convention (see `.claude/rules/theme/twig.md` § `uniqueId`):
 * A component can appear more than once on a page (a Gutenberg block in
 * WordPress, a paragraph in Drupal, a repeated include anywhere); relying on
 * `loop.index` alone gives every instance the same `id="slider-1"` series. Swiper
 * init / `aria-controls` lookups then attach to the wrong DOM nodes. The
 * canonical fix is `{% set block_id = uniqueId() %}` + composing the slider
 * id as `'slider-' ~ block_id ~ '-' ~ loop.index`.
 *
 * Detection (file-level, single pass at `tokenIndex === 0`):
 *
 *  1. **`loop.index` access** — search every non-string, non-comment token
 *     value for `loop.index`, `loop.index0`, or `loop.iteration` with a
 *     word-boundary guard. STRING_TYPE is excluded so `'loop.index'` as a
 *     literal class fragment (rare) doesn't false-positive.
 *
 *  2. **DOM identifier attribute** — search TEXT_TYPE tokens for `id=` /
 *     `aria-controls=` / `aria-labelledby=` / `aria-describedby=` /
 *     `aria-owns=` / `headers=` / `name=` / `for=`. These are the attributes
 *     whose value is interpreted as a document-unique identifier by HTML or
 *     by associated APIs (form labels, ARIA references). A `data-*`
 *     attribute carrying `loop.index` is harmless and intentionally not in
 *     the list.
 *
 *  3. **`uniqueId()` call** — search all tokens for `uniqueId(` (the macro
 *     defined in `templates/macro/parts/parts.twig` and registered as a
 *     global). Presence anywhere in the template is the opt-in.
 *
 *  4. Warn IFF (1) AND (2) AND NOT (3). Indirect cases — `{% set foo =
 *     loop.index %}` then `id="x-{{ foo }}"` — are caught because (1) sees
 *     the `loop.index` in the `set`, (2) sees the `id="…"`, (3) is absent.
 *
 * Known limitations (deliberate):
 *  - The rule does NOT verify that the `uniqueId()` result is actually
 *    *used* in the same identifier expression that interpolates
 *    `loop.index`. A template that calls `uniqueId()` for an unrelated
 *    purpose and ALSO uses `loop.index` in DOM ids passes the rule. This
 *    is acceptable trade-off: the false-negative is rare in practice, and
 *    the alternative (AST-level dataflow tracking through `set` indirection)
 *    is multiple orders of magnitude more complex.
 *  - Templates that emit `loop.index` only in `data-*` attributes / classes
 *    / inner text, but ALSO happen to have unrelated `id="…"` elsewhere,
 *    will warn. The fix is either adding `uniqueId()` (free belt-and-
 *    suspenders) or Layer-A disabling on the loop.index line with a reason.
 *
 * Implementation note: this rule runs once per file at `tokenIndex === 0`
 * and no-ops on all subsequent indices. It's file-level by nature; the
 * per-token `process()` callback is the only available hook in
 * `AbstractRule` (no `lintFile()` override surface — `final` in base).
 */
final class UniqueIdRequiredRule extends AbstractRule
{
	private const LOOP_INDEX_PATTERN = '/(?<![a-zA-Z0-9_])loop\.(?:index|index0|iteration)\b/';

	private const UNIQUE_ID_PATTERN = '/(?<![a-zA-Z0-9_])uniqueId\s*\(/';

	private const DOM_ID_ATTR_PATTERN = '/(?<![a-zA-Z0-9-])(?:id|aria-controls|aria-labelledby|aria-describedby|aria-owns|headers|name|for)\s*=\s*["\']/i';

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		// File-level rule: do the work once at the first token; no-op on the rest.
		if (0 !== $tokenIndex) {
			return;
		}

		$all = $tokens->toArray();
		if (count($all) === 0) {
			return;
		}

		// Twig tokenizes expressions into many small tokens — `loop.index` becomes
		// `NAME(loop) PUNCT(.) NAME(index)`, never a single regex-matchable token.
		// Concatenate all non-string, non-comment-text token values into one
		// scratch buffer and regex over that. Whitespace and EOL tokens are
		// preserved so the word-boundary lookbehinds work correctly.
		$scratch = '';
		$hasDomIdAttr = false;

		foreach ($all as $token) {
			$type = $token->getType();
			$value = $token->getValue();

			if (
				Token::STRING_TYPE !== $type
				&& Token::COMMENT_TEXT_TYPE !== $type
				&& Token::INLINE_COMMENT_TEXT_TYPE !== $type
			) {
				$scratch .= $value;
			}

			if (Token::TEXT_TYPE === $type && !$hasDomIdAttr && preg_match(self::DOM_ID_ATTR_PATTERN, $value)) {
				$hasDomIdAttr = true;
			}
		}

		$hasLoopIndex = (bool) preg_match(self::LOOP_INDEX_PATTERN, $scratch);
		$hasUniqueId = (bool) preg_match(self::UNIQUE_ID_PATTERN, $scratch);

		if (!$hasLoopIndex || !$hasDomIdAttr || $hasUniqueId) {
			return;
		}

		// Find the first token that introduces `loop.<index|index0|iteration>`
		// so the warning lands on the relevant line, not at the top of the file.
		$warningToken = $all[0];
		$total = count($all);
		for ($i = 0; $i < $total - 2; $i++) {
			$tok = $all[$i];
			if (Token::NAME_TYPE !== $tok->getType() || 'loop' !== $tok->getValue()) {
				continue;
			}
			$dot = $all[$i + 1];
			$prop = $all[$i + 2];
			if (
				// `.` moved from PUNCTUATION_TYPE to OPERATOR_TYPE in twig-cs-fixer 4.0.
				Token::OPERATOR_TYPE === $dot->getType() && '.' === $dot->getValue()
				&& Token::NAME_TYPE === $prop->getType()
				&& in_array($prop->getValue(), ['index', 'index0', 'iteration'], true)
			) {
				$warningToken = $tok;
				break;
			}
		}

		$this->addWarning(
			'loop.index participates in DOM identifier attributes (id/name/for/aria-*) but template never calls uniqueId(). '
			. 'Multiple instances of this component on the same page will collide on identical IDs. '
			. 'Fix: `{% set block_id = uniqueId() %}` at the top, then compose ids as `\'foo-\' ~ block_id ~ \'-\' ~ loop.index`. '
			. 'See twig.md § uniqueId.',
			$warningToken,
			'UniqueIdRequired',
		);
	}
}
