<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when `create_attribute({ class: [X] })` wraps a single static
 * string or a single variable reference in array brackets unnecessarily.
 *
 * The `create_attribute` helper accepts either a string or an array for
 * its `class:` slot — strings are emitted verbatim, arrays are joined.
 * Wrapping a single static value in `[...]` adds noise without intent;
 * the array form is reserved for two genuine cases:
 *   - multiple class sources composed together (`['btn', state_class]`),
 *   - conditional inclusion (`['btn', is_active ? 'is-active' : '']`).
 *
 * Detection (AST):
 *  - Match `FunctionExpression` whose name is `create_attribute`.
 *  - First positional argument must be an `ArrayExpression`.
 *  - Locate the `class` hash entry; value must be an `ArrayExpression`.
 *  - If the inner array has exactly 1 element AND that element is one of
 *    {`ConstantExpression` (string), `NameExpression` (which includes `ContextVariable`),
 *    `GetAttrExpression`} → fire.
 *
 * Skipped silently:
 *  - Inner array with 0 or >1 elements (composed lists are intentional).
 *  - Element is a compound expression (`ConditionalExpression`,
 *    `FilterExpression`, `BinaryExpression`, function call) — wrapping
 *    keeps `create_attribute`'s array-vs-string contract unambiguous
 *    when the reader would otherwise have to evaluate the expression.
 *  - Non-string `ConstantExpression` (numeric, bool) — out of scope.
 *  - `class:` value that isn't an array (already a string — fine).
 */
final class CreateAttributeClassArrayRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'create_attribute';

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

		$class = $this->findHashEntry($hash, 'class');
		if (!$class instanceof ArrayExpression) {
			return $node;
		}

		$elements = [];
		foreach ($class->getKeyValuePairs() as $pair) {
			$elements[] = $pair['value'];
		}

		if (1 !== \count($elements)) {
			return $node;
		}

		$only = $elements[0];

		if ($only instanceof ConstantExpression) {
			$value = $only->getAttribute('value');
			if (!\is_string($value)) {
				return $node;
			}
			$this->addWarning(
				"Single class source — drop the array brackets: class: '{$value}' instead of class: ['{$value}']",
				$class,
				'CreateAttributeClassArray',
			);

			return $node;
		}

		if ($only instanceof NameExpression
			|| $only instanceof GetAttrExpression
		) {
			$this->addWarning(
				'Single class source — drop the array brackets: class: X instead of class: [X]',
				$class,
				'CreateAttributeClassArray',
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
