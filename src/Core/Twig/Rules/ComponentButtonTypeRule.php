<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a `component_button({tag: 'button'})` call is missing
 * `wrapper_attributes.type`.
 *
 * Sibling check to `ButtonTypeRule` — that rule guards raw `<button>`
 * tags, this one guards the macro-driven path. The same HTML risk applies:
 * a button rendered without `type=` defaults to `submit` inside a `<form>`
 * and silently triggers form submission on click. Setting an explicit
 * `type` (`button` or `submit`) makes the intent unambiguous and survives
 * future form-context refactors.
 *
 * Detection (AST):
 *  - Match `FunctionExpression` whose name is `component_button`.
 *  - First positional argument must be an `ArrayExpression` (the hash
 *    literal — `component_button({ … })`); call sites that pass a
 *    pre-built variable are out of scope (we can't statically inspect them).
 *  - Look for a `tag` key — fire only when its value is the literal
 *    string `'button'`. Other tags (`a` default, `span`, `div`) skip
 *    the check.
 *  - Look for `wrapper_attributes` key. Must be an `ArrayExpression`
 *    containing a `type` entry whose value is a non-empty
 *    `ConstantExpression`. Anything else fires.
 *
 * Skipped silently:
 *  - Dynamic `tag:` (variable, ternary) — can't prove it's `'button'`.
 *  - Dynamic `wrapper_attributes:` (variable, ternary) — can't inspect.
 *  - Empty `tag: ''` — treated as default `'a'` per macro contract.
 */
final class ComponentButtonTypeRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'component_button';

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FunctionExpression) {
			return $node;
		}

		if (self::FUNCTION_NAME !== $node->getAttribute('name')) {
			return $node;
		}

		$arguments = $node->getNode('arguments');
		if (!$arguments->hasNode('0')) {
			return $node;
		}

		$hash = $arguments->getNode('0');
		if (!$hash instanceof ArrayExpression) {
			return $node;
		}

		$tag = $this->findHashEntry($hash, 'tag');
		if (!$tag instanceof ConstantExpression) {
			return $node;
		}

		if ('button' !== $tag->getAttribute('value')) {
			return $node;
		}

		$wrapperAttributes = $this->findHashEntry($hash, 'wrapper_attributes');
		if (null === $wrapperAttributes) {
			$this->addWarning(
				"component_button with tag: 'button' should set wrapper_attributes.type ('button' or 'submit')",
				$node,
				'ComponentButtonType',
			);

			return $node;
		}
		if (!$wrapperAttributes instanceof ArrayExpression) {
			// Dynamic value (variable, ternary) — can't statically inspect, skip.
			return $node;
		}

		$type = $this->findHashEntry($wrapperAttributes, 'type');
		if (!$type instanceof ConstantExpression) {
			$this->addWarning(
				"component_button with tag: 'button' should set wrapper_attributes.type ('button' or 'submit')",
				$node,
				'ComponentButtonType',
			);

			return $node;
		}

		$typeValue = $type->getAttribute('value');
		if (!\is_string($typeValue) || '' === $typeValue) {
			$this->addWarning(
				"component_button with tag: 'button' has empty wrapper_attributes.type — set 'button' or 'submit'",
				$node,
				'ComponentButtonType',
			);
		}

		return $node;
	}

	/**
	 * Walk the alternating key/value children of an ArrayExpression and return
	 * the value node for `$key` (if the key is a constant string). Returns null
	 * for missing keys, dynamic keys, or non-array nodes.
	 */
	private function findHashEntry(ArrayExpression $hash, string $key): ?Node
	{
		foreach ($hash->getKeyValuePairs() as $pair) {
			$pairKey = $pair['key'];
			if (!$pairKey instanceof ConstantExpression) {
				continue;
			}
			if ($pairKey->getAttribute('value') === $key) {
				return $pair['value'];
			}
		}

		return null;
	}
}
