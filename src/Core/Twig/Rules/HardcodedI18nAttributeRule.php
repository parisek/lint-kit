<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when an i18n-sensitive HTML attribute carries a hardcoded literal
 * instead of a `_x()` translation call.
 *
 * Every user-visible string in a translatable theme must flow through `_x()`
 * so locale loaders can swap copy at render time. Hardcoded values bypass
 * the translation pipeline and silently freeze the source-language string
 * into production — the failure mode is "the Czech site shows English /
 * the English site shows Czech" and it's invisible at code-review time
 * unless someone reads every attribute by hand.
 *
 * **Scope** — four user-facing attributes:
 *  - `aria-label` — assistive-technology label
 *  - `title` — tooltip / hover description
 *  - `alt` — image description (only when non-empty; the empty-value
 *    exemption keeps raw `<img alt="">` markup silent — `EmptyAltRule`
 *    is the companion that catches `{ alt: '' }` Twig hash literals,
 *    a different surface this rule never sees)
 *  - `placeholder` — form-input hint
 *
 * **Detection** — token-level scan over `TEXT_TYPE`. Twig's tokenizer
 * splits HTML text at whitespace, so `title="Click me"` lands across
 * multiple tokens (`title="Click`, space, `me">`). The rule:
 *  1. Locates each `<attr>=<quote>` opener inside a `TEXT_TYPE` token,
 *  2. If the closing quote sits in the same token, extracts the value
 *     directly; otherwise walks forward across subsequent tokens
 *     accumulating raw text until the matching closing quote is found
 *     (or a 500-char safety cap is hit),
 *  3. Then validates the captured value:
 *     - Empty / whitespace-only → skip (decorative `alt=""`)
 *     - Contains Twig interpolation (`{{ … }}` or `{% … %}`) → skip,
 *       assume it threads through `_x()` already
 *     - Contains no Unicode letter → skip (pure punctuation / digits /
 *       symbols typically not translatable: `title="$50"`, `title="—"`)
 *     - Otherwise → fire warning.
 *
 * **Why "any letter", not "non-English chars only"** — restricting to Czech
 * accented characters (`á č ď é ě í ň ó ř š ť ú ů ý ž`) would catch
 * `"Předchozí"` but miss ASCII-only Czech (`"Foto"`, `"Mapa"`) AND miss
 * hardcoded English on a Czech site (`"Submit"`, `"Close"`). The doctrine
 * is "every user-facing string goes through `_x()`" regardless of source
 * language. Letter-class detection is the cheapest predicate that catches
 * all hardcoded copy and reliably skips technical strings.
 *
 * **Companion rule** — `HardcodedI18nArrayKeyRule` (AST-based,
 * `AbstractNodeRule`) handles the matching
 * `create_attribute({ 'aria-label': 'Foo' })` Twig hash literal shape.
 * Both rules fire under the same lint label (`HardcodedI18nAttribute`)
 * so a single per-line disable silences either.
 *
 * **Known limitations** (accepted false negatives, per twig-cs-fixer.md):
 *  - **Mixed strings** (`title="Foo {{ name }}"`) — skipped because of the
 *    `{{` exemption. The "Foo" prefix should be translated, but the rule
 *    can't distinguish prefix from full interpolation without parsing.
 *
 * **Edge cases:**
 *  - Inside Twig comments → not a `TEXT_TYPE` token, ignored.
 *  - Brand identifiers (`alt="ACME-S"`) → fire by design. Project policy
 *    is to route brand strings through `_x()` (see styleguide.md §
 *    "Brand Assets — Hardcoded in the Component"). Use a per-line
 *    `twig-cs-fixer-disable-next-line HardcodedI18nAttribute` with reason
 *    if a particular brand really isn't translatable.
 */
final class HardcodedI18nAttributeRule extends AbstractRule
{
	/**
	 * Capture group 1: attribute name. Group 2: opening quote character.
	 *
	 * The `(?<![-:\w])` lookbehind anchors the attribute to a real HTML
	 * attribute boundary — a bare `\b` would also match the suffix half of
	 * `data-title=`, `:title=`, or `x-bind:title=` because `\b` is true
	 * between a `-` / `:` and a word char. Those are framework-specific
	 * bindings (Alpine `:title`, Vue `x-bind:title`, project `data-*`)
	 * that carry technical values, not user-facing copy, and would produce
	 * false positives across the rule's downstream surface.
	 */
	private const ATTR_OPEN_PATTERN = '/(?<![-:\w])(aria-label|title|alt|placeholder)\s*=\s*(["\'])/i';

	private const HAS_LETTER_PATTERN = '/\p{L}/u';

	private const HAS_INTERPOLATION_PATTERN = '/\{\{|\{%/';

	/** Safety cap: a single attribute value won't exceed this many bytes. */
	private const MAX_VALUE_LENGTH = 500;

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::TEXT_TYPE !== $token->getType()) {
			return;
		}

		$value = $token->getValue();
		if (!preg_match_all(self::ATTR_OPEN_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			return;
		}

		foreach ($matches as $match) {
			$attrName = strtolower($match[1][0]);
			$quoteChar = $match[2][0];
			$valueStartOffset = $match[2][1] + 1;

			$attrValue = $this->captureAttrValue(
				$value,
				$valueStartOffset,
				$quoteChar,
				$tokenIndex,
				$tokens,
			);

			if (null === $attrValue) {
				// Unclosed quote within the safety window — skip.
				continue;
			}

			if ('' === trim($attrValue)) {
				continue;
			}

			if (1 === preg_match(self::HAS_INTERPOLATION_PATTERN, $attrValue)) {
				continue;
			}

			if (1 !== preg_match(self::HAS_LETTER_PATTERN, $attrValue)) {
				continue;
			}

			$this->addWarning(
				\sprintf(
					'Hardcoded %s="%s" — wrap user-facing copy in _x() so translations resolve at render time',
					$attrName,
					$attrValue,
				),
				$token,
				'HardcodedI18nAttribute',
			);
		}
	}

	/**
	 * Capture the attribute value starting at `$startOffset` in `$tokenValue`,
	 * walking forward across subsequent tokens if the closing quote sits
	 * outside the current token. Returns `null` if no closing quote is found
	 * within the safety window.
	 */
	private function captureAttrValue(
		string $tokenValue,
		int $startOffset,
		string $quoteChar,
		int $tokenIndex,
		Tokens $tokens,
	): ?string {
		$rest = substr($tokenValue, $startOffset);
		$closeInTokenPos = strpos($rest, $quoteChar);

		if (false !== $closeInTokenPos) {
			return substr($rest, 0, $closeInTokenPos);
		}

		// Closing quote sits in a later token — accumulate forward.
		$accumulated = $rest;
		$total = \count($tokens->toArray());

		for ($i = $tokenIndex + 1; $i < $total; ++$i) {
			$neighbor = $tokens->get($i);
			$neighborValue = $neighbor->getValue();
			$closePos = strpos($neighborValue, $quoteChar);

			if (false !== $closePos) {
				return $accumulated . substr($neighborValue, 0, $closePos);
			}

			$accumulated .= $neighborValue;
			if (\strlen($accumulated) > self::MAX_VALUE_LENGTH) {
				return null;
			}
		}

		return null;
	}
}
