<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when an `alt` hash entry holds an empty string literal.
 *
 * `alt: ''` is the convention for decorative images, but it is also
 * the easiest accessibility mistake to make on a content image. The
 * rule fires on every empty literal so the developer can confirm the
 * intent: either add a description or move the image to the
 * decorative pattern (`role="presentation"` / explicit decorative
 * marker, depending on the surface).
 *
 * AST-aware port of the Python `empty-alt` regex. Walks every
 * `ArrayExpression`, finds entries keyed `alt`, and fires when the
 * value is a `ConstantExpression` whose string is empty. Anything
 * that isn't a bare string constant — variables, function calls,
 * concatenations — is skipped (likely intentional, dynamic value).
 *
 * Scope difference from the Python check: the regex was universal
 * but text-based (matched `alt: ''` anywhere in the source). The AST
 * port stays universal but precise — only literal hash entries
 * trigger, so it can't false-fire on prose comments or string
 * payloads that happen to contain `alt: ''`.
 */
final class EmptyAltRule extends AbstractNodeRule
{
	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ArrayExpression) {
			return $node;
		}

		foreach ($node->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			$value = $pair['value'];

			if (!$key instanceof ConstantExpression || 'alt' !== $key->getAttribute('value')) {
				continue;
			}

			if (!$value instanceof ConstantExpression) {
				// Variables, function calls, concatenations — assume intentional.
				continue;
			}

			$alt = $value->getAttribute('value');
			if (!\is_string($alt) || '' !== $alt) {
				continue;
			}

			$this->addWarning(
				'Empty alt attribute — add description or mark as decorative',
				$value,
				'EmptyAlt',
			);
		}

		return $node;
	}
}
