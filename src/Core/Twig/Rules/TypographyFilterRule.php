<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ForElseNode;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Suggests adding a `|typography` filter to printed text fields.
 *
 * Fires on `{{ <root>.<field> }}` where:
 *   - `<root>` is `content` or `heading` (canonical CMS namespaces),
 *   - `<field>` is one of the canonical text fields (title, perex, …),
 *   - the print expression has no filter applied at all.
 *
 * A loop variable counts as a root when its sequence is rooted at one:
 * `{% for item in content.items %}` makes `item.title` CMS content, and so
 * does `cell` in `{% for cell in row.cells %}` inside it. The name `item`
 * itself carries no meaning. It is overloaded across menu loops, navigation
 * entries and styleguide-chrome listings, where `|typography` would inject
 * markup into plain labels, so a loop over anything else stays out of scope.
 * Deciding by the sequence rather than by the name is what lets the rule see
 * the CMS loops without reaching into those. An inner loop that reuses the
 * name over another sequence shadows the outer one for its body, but not
 * for its `{% else %}` branch, where the loop target is not bound. A loop
 * key shadows an outer name too, and is never content itself. A `set` that
 * reassigns a loop's name inside the loop rebinds it by the new value,
 * unless the `set` sits inside an `{% if %}` within the loop: it may not run,
 * so the earlier binding stands. Not tracked: `{% with %}` scopes, and `set`
 * on a name no loop binds.
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
	 * One entry per open `{% for %}`: the names it binds, each with whether it
	 * is CMS content. The value target is content when the sequence is; the
	 * key target never is, but it still shadows an outer name. The innermost
	 * entry that binds a name wins.
	 *
	 * @var list<array<string, bool>>
	 */
	private array $loops = [];

	/**
	 * Open `{% if %}` depth, and the depth each open loop started at. A `set`
	 * rebinds a loop's name only at the loop's own depth: inside a branch it
	 * may not run, so the earlier binding stands.
	 *
	 * @var list<int>
	 */
	private array $loopDepths = [];

	private int $ifDepth = 0;

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

		if ($node instanceof ForNode) {
			$content = $this->isContentRooted($node->getNode('seq'));
			$names = [];
			$key = $this->targetName($node, 'key_target');
			if (null !== $key) {
				$names[$key] = false;
			}
			$value = $this->targetName($node, 'value_target');
			if (null !== $value) {
				$names[$value] = $content;
			}
			$this->loops[] = $names;
			$this->loopDepths[] = $this->ifDepth;

			return $node;
		}

		// `{% set item = … %}` inside a loop rebinds the loop's own name for the
		// rest of the body: it is content when the new value is. A name the
		// loops do not bind is left to the fixed roots, as before.
		if ($node instanceof SetNode) {
			$this->rebind($node);

			return $node;
		}

		if ($node instanceof IfNode) {
			++$this->ifDepth;

			return $node;
		}

		// `{% else %}` runs when the sequence is empty, so the loop target is
		// not bound there and an outer variable of that name is. The branch is
		// the loop's last child, so the top entry is its own loop.
		if ($node instanceof ForElseNode && [] !== $this->loops) {
			$this->loops[\count($this->loops) - 1] = [];

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

		if (!$this->isContentRoot($parts[0]) || !\in_array($parts[1], self::FIELDS, true)) {
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

	public function leaveNode(Node $node, Environment $env): Node
	{
		if ($node instanceof ForNode) {
			array_pop($this->loops);
			array_pop($this->loopDepths);
		}

		if ($node instanceof IfNode) {
			--$this->ifDepth;
		}

		return $node;
	}

	/**
	 * Whether a bare name refers to CMS content here: a fixed root, or the
	 * innermost open loop variable of that name when its sequence is content.
	 */
	private function isContentRoot(string $name): bool
	{
		for ($i = \count($this->loops) - 1; $i >= 0; --$i) {
			if (\array_key_exists($name, $this->loops[$i])) {
				return $this->loops[$i][$name];
			}
		}

		return \in_array($name, self::ROOTS, true);
	}

	/**
	 * Whether a loop sequence is rooted at CMS content. Filters are peeled
	 * (`content.items|slice(0, 3)` is still content); a sequence that is not a
	 * plain property path is not.
	 */
	private function isContentRooted(Node $seq): bool
	{
		while ($seq instanceof FilterExpression) {
			$seq = $seq->getNode('node');
		}

		$path = $this->extractPath($seq);
		if (null === $path) {
			return false;
		}

		return $this->isContentRoot(explode('.', $path)[0]);
	}

	private function rebind(SetNode $node): void
	{
		if (true === $node->getAttribute('capture')) {
			return;
		}

		$names = $this->listOf($node->getNode('names'));
		$values = $this->listOf($node->getNode('values'));
		foreach ($names as $index => $target) {
			$name = $target->hasAttribute('name') ? $target->getAttribute('name') : null;
			if (!\is_string($name)) {
				continue;
			}
			for ($i = \count($this->loops) - 1; $i >= 0; --$i) {
				if (\array_key_exists($name, $this->loops[$i])) {
					if ($this->loopDepths[$i] !== $this->ifDepth) {
						break;
					}
					$value = $values[$index] ?? null;
					$this->loops[$i][$name] = null !== $value && $this->isContentRooted($value);

					break;
				}
			}
		}
	}

	/**
	 * A `set` stores one name or value as the node itself and several as its
	 * children.
	 *
	 * @return list<Node>
	 */
	private function listOf(Node $node): array
	{
		if ($node->hasAttribute('name') || $node instanceof AbstractExpression) {
			return [$node];
		}

		$list = [];
		foreach ($node as $child) {
			$list[] = $child;
		}

		return $list;
	}

	private function targetName(ForNode $node, string $target): ?string
	{
		if (!$node->hasNode($target)) {
			return null;
		}

		$name = $node->getNode($target)->getAttribute('name');

		return \is_string($name) ? $name : null;
	}

	private function readTemplate(ModuleNode $node): void
	{
		$this->lines = [];
		$this->seen = [];
		$this->loops = [];
		$this->loopDepths = [];
		$this->ifDepth = 0;

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
	 * Whether text ending at a print leaves a quoted attribute value open.
	 *
	 * Scans from the last tag start on the line (`<` and a letter), or from
	 * the line start when the tag opened on an earlier line, and tracks the
	 * quote state: `=`, optional whitespace and a quote open a value, the same
	 * quote closes it, and a `>` outside a value closes the tag. Twig tags
	 * earlier on the line are blanked first, so a quote inside
	 * `{{ a ? 'b' : 'c' }}` does not count.
	 */
	private function opensAttributeValue(string $before): bool
	{
		$text = (string) preg_replace_callback(
			'/\{\{.*?\}\}|\{%.*?%\}/s',
			static fn (array $m): string => str_repeat('x', \strlen($m[0])),
			$before,
		);

		$start = 0;
		if (preg_match_all('/<[a-zA-Z]/', $text, $tags, \PREG_OFFSET_CAPTURE) > 0) {
			$start = $tags[0][\count($tags[0]) - 1][1];
		}

		$quote = null;
		$length = \strlen($text);
		for ($i = $start; $i < $length; ++$i) {
			$char = $text[$i];
			if (null !== $quote) {
				if ($char === $quote) {
					$quote = null;
				}

				continue;
			}
			if ('>' === $char) {
				return false;
			}
			if ('=' !== $char) {
				continue;
			}
			$j = $i + 1;
			while ($j < $length && ctype_space($text[$j])) {
				++$j;
			}
			if ($j < $length && ('"' === $text[$j] || "'" === $text[$j])) {
				$quote = $text[$j];
				$i = $j;
			}
		}

		return null !== $quote;
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
