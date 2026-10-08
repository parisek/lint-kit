<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when `value|resizer([w, h, _, 'crop'])` is called with crop mode
 * but width or height is empty/zero — the resizer silently ignores crop
 * without both dimensions and returns the original-size image.
 *
 * AST-aware port of the legacy `ResizerValidator` Python regex. Improvements
 * over the regex version:
 *
 *   - Detects number-literal dimensions (`[800, 600, '', 'crop']`); the regex
 *     only matched string-literal arrays, silently missing the most common case.
 *   - Trailing commas, whitespace, and named-argument hash variants
 *     (`{0: '', 1: '', 2: '', 3: 'crop'}`) are handled by the parser.
 *   - Variable dimensions (`[w, h, '', 'crop']`) are skipped — we can't
 *     statically evaluate them.
 */
final class ResizerCropRule extends AbstractNodeRule
{
	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FilterExpression) {
			return $node;
		}

		if ('resizer' !== $node->getAttribute('name')) {
			return $node;
		}

		$arguments = $node->getNode('arguments');
		if (!$arguments->hasNode('0')) {
			return $node;
		}

		$array = $arguments->getNode('0');
		if (!$array instanceof ArrayExpression) {
			return $node;
		}

		$values = $this->extractArrayValues($array);
		if (4 !== \count($values)) {
			return $node;
		}

		$crop = $values[3];
		if (!$crop instanceof ConstantExpression || 'crop' !== $crop->getAttribute('value')) {
			return $node;
		}

		$missing = [];
		foreach ([0 => 'width', 1 => 'height'] as $idx => $name) {
			if ($this->isMissingDimension($values[$idx])) {
				$missing[] = $name;
			}
		}

		if ([] !== $missing) {
			$this->addWarning(
				\sprintf(
					'resizer crop requires both width and height, missing: %s',
					implode(', ', $missing),
				),
				$node,
				'ResizerCrop',
			);
		}

		return $node;
	}

	/**
	 * Extract positional values from an `ArrayExpression`. Twig stores array
	 * literals as alternating key/value children: indices 0=key, 1=value,
	 * 2=key, 3=value, …
	 *
	 * @return list<Node>
	 */
	private function extractArrayValues(ArrayExpression $array): array
	{
		$values = [];
		$count = $array->count();
		for ($i = 1; $i < $count; $i += 2) {
			if ($array->hasNode((string) $i)) {
				$values[] = $array->getNode((string) $i);
			}
		}

		return $values;
	}

	/**
	 * A dimension counts as "missing" when it is a literal empty string,
	 * integer zero, or null. Non-constant expressions (variables, function
	 * calls, lookups) are treated as present — we cannot evaluate them at
	 * lint time.
	 */
	private function isMissingDimension(Node $value): bool
	{
		if (!$value instanceof ConstantExpression) {
			return false;
		}

		$literal = $value->getAttribute('value');

		return '' === $literal || 0 === $literal || null === $literal;
	}
}
