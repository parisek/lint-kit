<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * AST companion to `HardcodedI18nAttributeRule`. Catches the same hygiene
 * issue — user-facing copy hardcoded into i18n-sensitive slots without
 * `_x()` — but in the Twig hash literal shape passed to `create_attribute()`:
 *
 *     {% set button_attributes = create_attribute({
 *         'aria-label': 'Předchozí',   ← FAIL, would freeze Czech into production
 *     }) %}
 *
 *     {% set button_attributes = create_attribute({
 *         'aria-label': _x('Previous', '<theme>', '<theme>'),   ← OK, translatable
 *     }) %}
 *
 * **Scope — first argument of `create_attribute({…})`, with one filter unwrap.**
 * Plain data hashes (`component_xxx({ title: 'Foo' })`, `{% set demo = { alt: 'X' } %}`,
 * styleguide demo data, metadata blocks) all use the same `{ key: value }`
 * Twig syntax but are NOT HTML attributes — their `title` / `alt` /
 * `placeholder` keys are domain data, not user-facing attribute copy.
 * Restricting the scan to `create_attribute(…)` arguments removes that
 * ambiguity at the AST level (an early-tested broader walk produced
 * 1600+ false positives across one downstream theme's component data).
 *
 * The established `create_attribute({ … }|merge(…))` pattern (see
 * `picture.twig`) wraps the literal hash in a `FilterExpression`. The
 * rule unwraps one filter level so the inner `ArrayExpression` is still
 * scanned. Deeper filter chains (`|merge|merge`) are accepted as a false
 * negative — extremely rare in practice.
 *
 * Why a separate rule instead of extending `HardcodedI18nAttributeRule`:
 * the latter scans raw HTML text tokens (`TEXT_TYPE`) and `extends
 * AbstractRule`; AST-level walks over `FunctionExpression` need
 * `AbstractNodeRule`. A single class can't extend both. Both rules fire
 * under the same lint label (`HardcodedI18nAttribute`) so a single
 * per-line disable silences either one.
 *
 * **Keys watched** (matches `HardcodedI18nAttributeRule`):
 * `aria-label`, `title`, `alt`, `placeholder`.
 *
 * **Fires when:**
 *  - The node is a `FunctionExpression` named `create_attribute`,
 *  - Its first argument is an `ArrayExpression`,
 *  - One of its entries has a `ConstantExpression` key whose string
 *    matches an i18n key (case-insensitive),
 *  - Whose value is a `ConstantExpression` (literal string — not a
 *    function call, variable, concat, or computed expression),
 *  - Which has at least one Unicode letter and is non-empty.
 *
 * **Skipped (by design):**
 *  - `'alt': ''` → empty value, already covered by `EmptyAltRule`.
 *  - `'aria-label': content.label` → variable, assumed already translated upstream.
 *  - `'aria-label': _x(...)` → function call, not a `ConstantExpression`.
 *  - `'aria-label': someVar ~ ' …'` → concatenation, not a `ConstantExpression`.
 *  - `'title': '$50'` → no Unicode letter, technical string.
 *  - Plain data hashes outside `create_attribute()` → never inspected.
 */
final class HardcodedI18nArrayKeyRule extends AbstractNodeRule
{
	private const TARGET_FUNCTION = 'create_attribute';

	private const I18N_KEYS = ['aria-label', 'title', 'alt', 'placeholder'];

	private const HAS_LETTER_PATTERN = '/\p{L}/u';

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FunctionExpression) {
			return $node;
		}

		$name = $node->getAttribute('name');
		if (self::TARGET_FUNCTION !== $name) {
			return $node;
		}

		if (!$node->hasNode('arguments')) {
			return $node;
		}
		$args = $node->getNode('arguments');

		if (!$args->hasNode('0')) {
			return $node;
		}
		$firstArg = $args->getNode('0');

		// Unwrap one level of filter chain — `create_attribute({ … }|merge(…))`
		// is the established merge pattern (see picture.twig), so the array
		// literal sits inside a FilterExpression rather than directly as the
		// argument. The inner node is the array we want to scan.
		if ($firstArg instanceof FilterExpression && $firstArg->hasNode('node')) {
			$firstArg = $firstArg->getNode('node');
		}

		if (!$firstArg instanceof ArrayExpression) {
			return $node;
		}

		foreach ($firstArg->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			$value = $pair['value'];

			if (!$key instanceof ConstantExpression) {
				continue;
			}

			$keyValue = $key->getAttribute('value');
			if (!\is_string($keyValue)) {
				continue;
			}

			if (!\in_array(strtolower($keyValue), self::I18N_KEYS, true)) {
				continue;
			}

			if (!$value instanceof ConstantExpression) {
				// Variables, function calls (incl. _x()), concatenations — assume intentional / translated.
				continue;
			}

			$stringValue = $value->getAttribute('value');
			if (!\is_string($stringValue)) {
				continue;
			}

			if ('' === trim($stringValue)) {
				// Empty (decorative `alt: ''`) — covered by EmptyAltRule.
				continue;
			}

			if (1 !== preg_match(self::HAS_LETTER_PATTERN, $stringValue)) {
				continue;
			}

			// Attach the warning to the literal *value* node, not the enclosing
			// `create_attribute(…)` call. This places the reported line at the
			// offending entry's line so `twig-cs-fixer-disable-next-line
			// HardcodedI18nAttribute` immediately above the entry silences only
			// that entry — same precision as `EmptyAltRule`.
			$this->addWarning(
				\sprintf(
					"Hardcoded create_attribute %s: '%s' — wrap user-facing copy in _x() so translations resolve at render time",
					$keyValue,
					$stringValue,
				),
				$value,
				'HardcodedI18nAttribute',
			);
		}

		return $node;
	}
}
