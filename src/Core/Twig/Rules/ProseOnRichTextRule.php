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
 * Warns when `{{ content.<rich_text_field>|typography }}` is printed
 * without an HTML ancestor carrying a `prose` class.
 *
 * The `|typography` filter applies typographic micro-fixes (widow
 * prevention, non-breaking spaces, smart quotes). For multi-paragraph or
 * HTML-bearing CMS content, the project convention is to wrap that
 * output in a `prose`-classed element so the Tailwind Typography plugin
 * also styles nested `<p>`, `<ul>`, `<h2>`, `<blockquote>`, etc. Without
 * `prose`, rich content renders unstyled and inherits whatever the
 * default cascade gives it — usually wrong.
 *
 * Rich-text fields are documented in twig.md "Use prose Class for
 * Description/Perex Text". Anything else (`title`, `subtitle`, `label`,
 * `name`) is short-form and intentionally NOT in scope.
 *
 * Detection (hybrid):
 *  - **AST**: matches `PrintNode` whose innermost expression is
 *    `GetAttrExpression` of the form `content.<field>` where `<field>`
 *    is one of the rich-text fields. Skips when the print has no
 *    `|typography` filter (the field might be plain HTML or technical).
 *  - **Source scan**: the rule reads the template file and walks all
 *    HTML tags from the start of the file up to the print line,
 *    maintaining a stack of open tags. Each ancestor's `class="…"`
 *    attribute is inspected for any `prose*` Tailwind utility. If at
 *    least one ancestor matches, the print is considered prose-wrapped.
 *
 * Skip conditions:
 *  - Templates inside `styleguide` (intentional out-of-context demos).
 *  - Print expressions whose typography arg is dynamic / non-constant.
 *  - Templates that can't be read from disk (e.g. compiled-only mode).
 *  - Ancestors with dynamic class (`<div{{ wrapper_attributes }}>`) —
 *    ignored on the conservative side; we can't statically prove
 *    they're missing prose.
 *
 * Limitations (acceptable false negatives, not false positives):
 *  - Conditional Twig branches (`{% if %}<div class="prose">{% endif %}`)
 *    confuse the stack — the rule may believe an unconditional `<div>`
 *    is open. We err on the side of fewer warnings.
 *  - Blocks defined elsewhere (parent template's wrapper providing
 *    `prose`) are invisible. Templates extending a base whose wrapper
 *    has `prose` will still fire — acceptable: most rich-text rendering
 *    happens inside `block content`, not on the wrapper.
 */
final class ProseOnRichTextRule extends AbstractNodeRule
{
	private const RICH_TEXT_FIELDS = ['description', 'perex', 'text', 'body', 'article_text'];

	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
		'input', 'link', 'meta', 'source', 'track', 'wbr',
	];

	/** @var list<string> */
	private array $sourceLines = [];

	public function enterNode(Node $node, Environment $env): Node
	{
		if ($node instanceof ModuleNode) {
			$this->loadSourceLines($node);

			return $node;
		}

		if (!$node instanceof PrintNode) {
			return $node;
		}

		if ([] === $this->sourceLines) {
			return $node;
		}

		$field = $this->extractRichTextField($node);
		if (null === $field) {
			return $node;
		}

		if ($this->hasProseAncestor($node->getTemplateLine())) {
			return $node;
		}

		$this->addWarning(
			\sprintf(
				"{{ content.%s|typography }} should be inside an element with 'prose' class",
				$field,
			),
			$node,
			'ProseOnRichText',
		);

		return $node;
	}

	public function leaveNode(Node $node, Environment $env): Node
	{
		if ($node instanceof ModuleNode) {
			$this->sourceLines = [];
		}

		return $node;
	}

	private function loadSourceLines(ModuleNode $module): void
	{
		$this->sourceLines = [];

		$templateName = $module->getTemplateName();
		if (null === $templateName) {
			return;
		}
		if (str_contains(basename($templateName), 'styleguide')) {
			return;
		}
		if (!is_readable($templateName)) {
			return;
		}

		$contents = file_get_contents($templateName);
		if (false === $contents) {
			return;
		}

		$this->sourceLines = explode("\n", $contents);
	}

	/**
	 * Return the rich-text field name (e.g. `description`) when this PrintNode
	 * matches `{{ content.<rich_text_field>|typography }}`, else null.
	 */
	private function extractRichTextField(PrintNode $node): ?string
	{
		$expr = $node->getNode('expr');

		// Twig wraps every print in `|escape(...)` autoescape; peel through it
		// while watching for a `|typography` filter — that's the marker that
		// the author opted into rich-text rendering.
		$hasTypography = false;
		while ($expr instanceof FilterExpression) {
			$name = $expr->getAttribute('name');
			if ('typography' === $name) {
				$hasTypography = true;
			}
			$expr = $expr->getNode('node');
		}

		if (!$hasTypography) {
			return null;
		}

		if (!$expr instanceof GetAttrExpression) {
			return null;
		}

		if ('method' === $expr->getAttribute('type')) {
			return null;
		}

		$attr = $expr->getNode('attribute');
		if (!$attr instanceof ConstantExpression) {
			return null;
		}

		$field = $attr->getAttribute('value');
		if (!\is_string($field) || !\in_array($field, self::RICH_TEXT_FIELDS, true)) {
			return null;
		}

		$root = $expr->getNode('node');
		if (!$root instanceof NameExpression) {
			return null;
		}

		if ('content' !== $root->getAttribute('name')) {
			return null;
		}

		return $field;
	}

	/**
	 * Walk every HTML tag from start of file up to the print's `{{` marker,
	 * maintaining a stack of open elements. Returns true when at least one
	 * stacked ancestor's `class` attribute carries a `prose*` token OR any
	 * heading-style class (`font-headings`, `text-heading-*`, `text-display-*`,
	 * `text-title-*`). Heading elements use `|typography` for micro-fixes on
	 * inline text — they intentionally don't carry `prose` because the
	 * Typography plugin's block-level styles are not wanted for single-line
	 * headings.
	 *
	 * The walked range INCLUDES the print line — truncated at the first
	 * `{{` on that line — so single-line wrappers like
	 * `<div class="prose">{{ x|typography }}</div>` correctly recognize the
	 * `<div>` as an ancestor without also seeing the trailing `</div>`.
	 */
	private function hasProseAncestor(int $printLine): bool
	{
		$lines = \array_slice($this->sourceLines, 0, $printLine);
		if (isset($lines[$printLine - 1])) {
			$bracePos = strpos($lines[$printLine - 1], '{{');
			if (false !== $bracePos) {
				$lines[$printLine - 1] = substr($lines[$printLine - 1], 0, $bracePos);
			}
		}
		$source = implode("\n", $lines);

		// Strip Twig comments (`{# … #}`) to avoid matching tags inside them.
		$source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;

		if (!preg_match_all(
			'#<(/?)([a-z][a-z0-9]*)\b([^>]*?)(/?)>#is',
			$source,
			$matches,
			PREG_SET_ORDER,
		)) {
			return false;
		}

		/** @var list<array{tag: string, class: string}> $stack */
		$stack = [];

		foreach ($matches as $m) {
			$isClose = '/' === $m[1];
			$tag = strtolower($m[2]);
			$attrs = $m[3];
			$selfClosing = '/' === $m[4];

			if ($isClose) {
				for ($i = \count($stack) - 1; $i >= 0; --$i) {
					if ($stack[$i]['tag'] === $tag) {
						array_splice($stack, $i);
						break;
					}
				}
				continue;
			}

			if ($selfClosing || \in_array($tag, self::VOID_ELEMENTS, true)) {
				continue;
			}

			$class = '';
			if (preg_match('/\bclass\s*=\s*"([^"]*)"/is', $attrs, $cm)
				|| preg_match("/\\bclass\\s*=\\s*'([^']*)'/is", $attrs, $cm)
			) {
				$class = $cm[1];
			}

			$stack[] = ['tag' => $tag, 'class' => $class];
		}

		foreach ($stack as $ancestor) {
			if ($this->classHasProse($ancestor['class'])) {
				return true;
			}
			if ($this->classHasHeading($ancestor['class'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true when any whitespace-separated token in `$classAttr` is a
	 * `prose*` utility (with optional Tailwind variant prefix like `lg:`).
	 *
	 * Matches: `prose`, `prose-lg`, `prose-invert`, `lg:prose`, `dark:prose-xl`.
	 * Skips: `something-prose`, `prosaic`.
	 */
	private function classHasProse(string $classAttr): bool
	{
		if ('' === $classAttr) {
			return false;
		}

		foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $token) {
			if (1 === preg_match('/(?:^|:)prose(?:-|$)/', $token)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true when any token in `$classAttr` signals a heading element
	 * — i.e. the author is printing short inline text through `|typography`
	 * for widow prevention / smart quotes, NOT wrapping multi-paragraph CMS
	 * rich text that needs the Tailwind Typography plugin.
	 *
	 * Matches (with optional Tailwind variant prefix):
	 *   - `font-headings`                   — project heading font family
	 *   - `text-heading-*`                  — semantic heading size token
	 *   - `text-display-*`                  — display/hero heading size token
	 *   - `text-title-*`                    — title size token
	 *   - `text-[clamp(…)]`                 — bespoke fluid sizing; the author
	 *     already owns the type treatment, so the Typography plugin's
	 *     block-level styles are unwanted (same computed-form carve-out as
	 *     HeadingArbitraryFontSizeRule). Back-promoted from fellows, where
	 *     4 perex call sites needed an identical Layer A disable.
	 */
	private function classHasHeading(string $classAttr): bool
	{
		if ('' === $classAttr) {
			return false;
		}

		foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $token) {
			// Strip ALL leading Tailwind variant prefixes, including stacked ones
			// (`xl:`, `lg:`, `dark:lg:`, `max-md:hover:`) so the heading-class match
			// below sees the bare utility (`text-heading-lg`).
			$bare = preg_replace('/^(?:[a-z0-9_-]+:)+/', '', $token) ?? $token;
			if ('font-headings' === $bare
				|| str_starts_with($bare, 'text-heading-')
				|| str_starts_with($bare, 'text-display-')
				|| str_starts_with($bare, 'text-title-')
				|| str_starts_with($bare, 'text-[clamp(')
			) {
				return true;
			}
		}

		return false;
	}
}
