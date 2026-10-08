<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\Binary\BitwiseOrBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\Filter\RawFilter;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when `|json_encode` lands in an HTML-attribute context without an
 * adequate escape contract.
 *
 * Two safe shapes are accepted (per `.claude/docs/theme/create-attribute.md`
 * § "Passing Complex Data to JS via `data-*`"):
 *
 *   1. **Raw HTML attribute** — `{{ x|json_encode|e('html_attr') }}` (or
 *      `|escape('html_attr')`). The `e('html_attr')` filter is itself the
 *      escape; no `|raw` is needed or wanted.
 *   2. **Inside `create_attribute({ 'data-x': … })`** —
 *      `value|json_encode(JSON_HEX_QUOT, …)|raw`. The `JSON_HEX_*` flags
 *      are the sole escape contract; `|raw` is required because
 *      `create_attribute` would otherwise re-mangle through default
 *      auto-escape.
 *
 * Detection algorithm — per `FilterExpression` named `json_encode`:
 *
 *   1. **Flag check.** Walk the filter's `arguments` recursively, descending
 *      into `BitwiseOrBinary` (the `b-or` operator) and into
 *      `FunctionExpression('constant')` calls. If any reachable
 *      `ConstantExpression` is the string `'JSON_HEX_QUOT'`, accept.
 *      (Bare `JSON_HEX_QUOT` resolves to a NameExpression and would be
 *      undefined; the doctrine canonical is `constant('JSON_HEX_QUOT')`.)
 *   2. **Wrapping-filter check.** If a wrapping filter in the chain is
 *      `e('html_attr')` or `escape('html_attr')`, accept.
 *   3. **Context skip.** Only fire when the `json_encode` sits inside a
 *      `PrintNode` (raw HTML attribute output) OR inside an
 *      `ArrayExpression` value (the `create_attribute` payload shape).
 *      `{% set foo = obj|json_encode %}` for non-attribute purposes is
 *      conservatively skipped.
 *   4. Otherwise, fire.
 *
 * Walking the chain — Twig nests outer filters as parents
 * (`x|json_encode|e('html_attr')` is `e(html_attr)` wrapping `json_encode`
 * wrapping `x`). `AbstractNodeRule::enterNode` fires per-node without
 * direct parent access, so this rule walks the AST manually from each
 * `PrintNode` / `ArrayExpression` value entry point and tracks the
 * wrapping-filter stack in a local variable. Mirrors
 * `UnguardedOutputRule::walkNode` (lines 91–134) and is the recommended
 * "approach B" from the rule's spec.
 *
 * Known limitations:
 *  - **Indirect path false negatives.** `{% set x = obj|json_encode %}`
 *    followed by `data-foo="{{ x }}"` is invisible to the rule — the
 *    assignment is in scope, the consumption is not.
 *  - **Custom flag combinations without `JSON_HEX_QUOT`** that happen to
 *    be safe by accident → false positive. Disable per Layer A with a
 *    reason (`twig-cs-fixer.md` § 3).
 *  - **`_x()`-only translation payloads** are doctrinally allowed to use
 *    bare `|json_encode|raw` (see `create-attribute.md`); this carve-out
 *    lives in doctrine, not in the rule. Layer A disable per call site.
 */
final class JsonEncodeHtmlAttrRule extends AbstractNodeRule
{
	private const FILTER_NAME = 'json_encode';
	private const SAFE_FLAG = 'JSON_HEX_QUOT';
	private const ESCAPE_FILTERS = ['e', 'escape'];
	private const ESCAPE_STRATEGY = 'html_attr';
	private const WARNING_MESSAGE = "|json_encode in HTML attribute context needs |e('html_attr') (or json_encode flag JSON_HEX_QUOT) — unescaped quotes break out of the attribute";

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ModuleNode) {
			return $node;
		}

		$this->walkNode($node, [], false);

		return $node;
	}

	/**
	 * Walks the AST manually so we can carry the stack of wrapping filters
	 * (needed for the `|e('html_attr')` lookup) and the current context flag
	 * (PrintNode or ArrayExpression value).
	 *
	 * @param list<FilterExpression> $wrappingFilters Filters wrapping the current
	 *        position, **innermost first** — index 0 is the filter directly
	 *        wrapping the current node (built by prepending each filter as we
	 *        descend from outer to inner, so the most recently-pushed entry is
	 *        the closest parent). Reset to `[]` whenever we cross a non-filter
	 *        boundary, since `e('html_attr')` only neutralises a directly-chained
	 *        `json_encode`, not one nested deeper.
	 */
	private function walkNode(Node $node, array $wrappingFilters, bool $inAttrContext): void
	{
		if ($node instanceof PrintNode) {
			// Print output sinks into raw template HTML — attribute context.
			$this->walkNode($node->getNode('expr'), [], true);

			return;
		}

		if ($node instanceof FilterExpression) {
			if (self::FILTER_NAME === $node->getAttribute('name') && $inAttrContext) {
				if (!$this->isSafeJsonEncode($node, $wrappingFilters)) {
					$this->addWarning(self::WARNING_MESSAGE, $node, 'JsonEncodeHtmlAttr');
				}
			}

			// Recurse into the wrapped expression with this filter pushed.
			// `RawFilter` is a `FilterExpression` subclass — its `arguments` is
			// `EmptyNode`, but the wrapping-filter logic below only inspects
			// `name` + `getNode('arguments')`, so RawFilter participates in the
			// chain naturally.
			$inner = $node->getNode('node');
			$this->walkNode($inner, array_merge([$node], $wrappingFilters), $inAttrContext);

			// Filter arguments are independent sub-expressions — they don't
			// inherit the attribute context (e.g. `|default(some|json_encode)`
			// shouldn't drag attr-context into the default value).
			if ($node->hasNode('arguments')) {
				$this->walkNode($node->getNode('arguments'), [], false);
			}

			return;
		}

		// Crossing into a hash literal: each value enters attribute context
		// because the array might be the payload of `create_attribute({})`.
		// We can't statically distinguish "create_attribute call" from "arbitrary
		// array literal", so we conservatively treat every ArrayExpression value
		// as attr-bearing — false positives are rare and silenceable.
		// `getKeyValuePairs()` is the canonical accessor on `ArrayExpression`.
		if ($node instanceof \Twig\Node\Expression\ArrayExpression) {
			foreach ($node->getKeyValuePairs() as $pair) {
				$this->walkNode($pair['key'], [], false);
				$this->walkNode($pair['value'], [], true);
			}

			return;
		}

		// Default: recurse into all children, dropping the wrapping-filter stack
		// because we've crossed a non-filter boundary (the chain no longer
		// directly composes with the inner expression).
		foreach ($node as $child) {
			if ($child instanceof Node) {
				$this->walkNode($child, [], $inAttrContext);
			}
		}
	}

	/**
	 * @param list<FilterExpression> $wrappingFilters
	 */
	private function isSafeJsonEncode(FilterExpression $filter, array $wrappingFilters): bool
	{
		// Check #1 — JSON_HEX_QUOT flag inside the filter arguments.
		if ($filter->hasNode('arguments') && $this->argumentsContainSafeFlag($filter->getNode('arguments'))) {
			return true;
		}

		// Check #2 — a wrapping `e('html_attr')` / `escape('html_attr')` filter.
		// `wrappingFilters[0]` is the filter directly wrapping json_encode; the
		// stack is built innermost-first by prepending each filter as the walker
		// descends from outer to inner, so the most recently-pushed entry sits
		// at index 0. Walking the whole list is fine — even a non-adjacent
		// `e('html_attr')` further out neutralises the chain.
		foreach ($wrappingFilters as $wrapping) {
			if ($this->isHtmlAttrEscape($wrapping)) {
				return true;
			}
		}

		return false;
	}

	private function argumentsContainSafeFlag(Node $arguments): bool
	{
		foreach ($arguments as $arg) {
			if ($arg instanceof Node && $this->expressionMentionsSafeFlag($arg)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively descend through `BitwiseOrBinary` (the `b-or` operator),
	 * looking for `constant('JSON_HEX_QUOT')` function calls.
	 *
	 * Only `constant('JSON_HEX_QUOT')` is accepted — that's the doctrine
	 * canonical form (bare `JSON_HEX_QUOT` resolves to a runtime-undefined
	 * NameExpression in Twig, and a literal string `'JSON_HEX_QUOT'` passed
	 * as the `json_encode` flag would silently coerce to `0` at runtime
	 * (no flags), so accepting either shape would be a false negative).
	 */
	private function expressionMentionsSafeFlag(Node $node): bool
	{
		if ($node instanceof BitwiseOrBinary) {
			return $this->expressionMentionsSafeFlag($node->getNode('left'))
				|| $this->expressionMentionsSafeFlag($node->getNode('right'));
		}

		if (!$node instanceof FunctionExpression || 'constant' !== $node->getAttribute('name')) {
			return false;
		}

		$args = $node->getNode('arguments');
		if (!$args->hasNode('0')) {
			return false;
		}

		$first = $args->getNode('0');

		return $first instanceof ConstantExpression
			&& self::SAFE_FLAG === $first->getAttribute('value');
	}

	private function isHtmlAttrEscape(FilterExpression $filter): bool
	{
		// `RawFilter` is a `FilterExpression` subclass whose `name` is `'raw'` —
		// excluded from the escape-filter list. `|raw` is the unsafe sibling
		// the rule explicitly fires on when paired with bare `|json_encode`.
		if ($filter instanceof RawFilter) {
			return false;
		}

		if (!\in_array($filter->getAttribute('name'), self::ESCAPE_FILTERS, true)) {
			return false;
		}

		if (!$filter->hasNode('arguments')) {
			return false;
		}

		$arguments = $filter->getNode('arguments');
		$strategy = null;
		if ($arguments->hasNode('0')) {
			$strategy = $arguments->getNode('0');
		} elseif ($arguments->hasNode('strategy')) {
			$strategy = $arguments->getNode('strategy');
		}

		return $strategy instanceof ConstantExpression
			&& self::ESCAPE_STRATEGY === $strategy->getAttribute('value');
	}
}
