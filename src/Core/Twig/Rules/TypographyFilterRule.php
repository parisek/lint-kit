<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
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
 * A print inside an HTML attribute value is skipped. An attribute is a
 * technical or accessibility string (`title`, `aria-label`, an `id` built
 * from a field), and `|typography` does not belong there: its non-breaking
 * spaces and smart quotes would reach a screen reader or a selector. The rule
 * reads the template's own line for this, because the AST carries no HTML
 * context. One known gap: an attribute value that opens on an earlier line
 * than the print is not seen, and the print is reported.
 *
 * Enforces `theme/typography.md` § `|typography` Filter and the prose vs
 * technical split in `theme/twig.md` § Translations (tailwind-base).
 *
 * Any filter (including `|raw`, `|escape`) silences the warning — same
 * lenient policy as the Python `missing-typography` regex it replaces.
 * Only bare `GetAttrExpression` top expressions of length 2 trigger;
 * function calls, null-coalesce, concat, set-target are out of scope.
 */
final class TypographyFilterRule extends AbstractNodeRule
{
	private const ROOTS = ['content', 'heading'];

	/**
	 * Lines of the template being linted, 1-indexed via `$lines[$n - 1]`.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * How many prints of a path this rule has seen on a line so far, so the
	 * n-th print maps to the n-th `{{ path }}` span on that line.
	 *
	 * @var array<string, int>
	 */
	private array $seen = [];

	private const FIELDS = ['title', 'perex', 'subtitle', 'description', 'label', 'name', 'heading'];

	public function enterNode(Node $node, Environment $env): Node
	{
		if ($node instanceof ModuleNode) {
			$this->readTemplate($node);

			return $node;
		}

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

		if ($this->printsInsideAttribute($node, $path)) {
			return $node;
		}

		$this->addWarning(
			\sprintf('Consider adding |typography filter to .%s', $parts[1]),
			$node,
			'TypographyFilter',
		);

		return $node;
	}

	private function readTemplate(ModuleNode $node): void
	{
		$this->lines = [];
		$this->seen = [];

		$code = $node->getSourceContext()?->getCode() ?? '';
		if ('' !== $code) {
			$this->lines = explode("\n", $code);
		}
	}

	/**
	 * Whether this print sits inside a quoted HTML attribute value.
	 *
	 * Finds every `{{ path }}` span on the print's line and takes the one this
	 * print is, by order of appearance. When the line cannot be read, or the
	 * span cannot be found (the print is written in a shape this scan does not
	 * match), the answer is no and the rule reports as before.
	 */
	private function printsInsideAttribute(PrintNode $node, string $path): bool
	{
		$lineNumber = $node->getTemplateLine();
		$line = $this->lines[$lineNumber - 1] ?? null;
		if (null === $line) {
			return false;
		}

		$pattern = '/\{\{-?~?\s*' . preg_quote($path, '/') . '\s*-?~?\}\}/';
		if (false === preg_match_all($pattern, $line, $matches, \PREG_OFFSET_CAPTURE)) {
			return false;
		}

		$key = $lineNumber . ':' . $path;
		$index = $this->seen[$key] ?? 0;
		$this->seen[$key] = $index + 1;

		$span = $matches[0][$index] ?? null;
		if (null === $span) {
			return false;
		}

		return $this->opensAttributeValue(substr($line, 0, $span[1]));
	}

	/**
	 * Whether text ending at a print leaves a quoted attribute value open:
	 * the last `=` followed by a quote (whitespace allowed on both sides of
	 * the `=`) has no matching closing quote after it.
	 */
	private function opensAttributeValue(string $before): bool
	{
		if (false === preg_match_all('/=\s*(["\'])/', $before, $matches, \PREG_OFFSET_CAPTURE) || [] === $matches[1]) {
			return false;
		}

		[$quote, $at] = $matches[1][\count($matches[1]) - 1];

		return !str_contains(substr($before, $at + 1), $quote);
	}

	/**
	 * Extract a dotted path from a chain of GetAttrExpression rooted at a
	 * NameExpression. Returns null for method calls, dynamic attributes,
	 * or anything not a plain property access.
	 */
	private function extractPath(Node $node): ?string
	{
		if ($node instanceof NameExpression) {
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
