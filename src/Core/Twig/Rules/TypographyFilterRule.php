<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Suggests adding a `|typography` filter to printed text fields.
 *
 * Fires on `{{ <root>.<field> }}` where:
 *   - `<root>` is `content` or `heading` (canonical CMS namespaces),
 *   - `<field>` is one of the canonical text fields (title, perex, …),
 *   - the print expression has no filter applied at all.
 *
 * `item` is intentionally excluded from ROOTS: in this codebase `item` is
 * overloaded across menu loops, navigation entries, and styleguide-chrome
 * metadata listings (component name labels, badges) where `|typography`
 * would inject unwanted markup into plain labels. False positives on a
 * warning rule are a worse UX than a few missed suggestions inside
 * genuine `{% for item in content.items %}` CMS loops — those authors
 * can always reach for `|typography` themselves or alias the loop
 * variable (`{% set content = item %}`) when they want coverage.
 *
 * Any filter (including `|raw`, `|escape`) silences the warning — same
 * lenient policy as the Python `missing-typography` regex it replaces.
 * Only bare `GetAttrExpression` top expressions of length 2 trigger;
 * function calls, null-coalesce, concat, set-target are out of scope.
 */
final class TypographyFilterRule extends AbstractNodeRule
{
	private const ROOTS = ['content', 'heading'];

	private const FIELDS = ['title', 'perex', 'subtitle', 'description', 'label', 'name', 'heading'];

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof PrintNode) {
			return $node;
		}

		$current = $node->getNode('expr');

		// Twig's autoescape NodeVisitor wraps every print in `|escape(...)`, so the
		// PrintNode's expr is almost always a FilterExpression even when the source
		// has no user-supplied filter. Peel through the chain and short-circuit if
		// any non-escape filter is present — that's the developer signaling intent.
		while ($current instanceof FilterExpression) {
			$name = $current->getAttribute('name');
			if ('escape' !== $name && 'e' !== $name) {
				return $node;
			}
			$current = $current->getNode('node');
		}

		if (!$current instanceof GetAttrExpression) {
			return $node;
		}

		$path = $this->extractPath($current);
		if (null === $path) {
			return $node;
		}

		$parts = explode('.', $path);
		if (2 !== \count($parts)) {
			return $node;
		}

		if (!\in_array($parts[0], self::ROOTS, true) || !\in_array($parts[1], self::FIELDS, true)) {
			return $node;
		}

		$this->addWarning(
			\sprintf('Consider adding |typography filter to .%s', $parts[1]),
			$node,
			'TypographyFilter',
		);

		return $node;
	}

	/**
	 * Extract a dotted path from a chain of GetAttrExpression rooted at a
	 * NameExpression. Returns null for method calls, dynamic attributes,
	 * or anything not a plain property access.
	 */
	private function extractPath(Node $node): ?string
	{
		if ($node instanceof NameExpression || $node instanceof ContextVariable) {
			$name = $node->getAttribute('name');

			return \is_string($name) ? $name : null;
		}

		if (!$node instanceof GetAttrExpression) {
			return null;
		}

		if ('method' === $node->getAttribute('type')) {
			return null;
		}

		$parentPath = $this->extractPath($node->getNode('node'));
		if (null === $parentPath) {
			return null;
		}

		$attr = $node->getNode('attribute');
		if (!$attr instanceof ConstantExpression) {
			return null;
		}

		$attrValue = $attr->getAttribute('value');
		if (!\is_string($attrValue)) {
			return null;
		}

		return $parentPath . '.' . $attrValue;
	}
}
