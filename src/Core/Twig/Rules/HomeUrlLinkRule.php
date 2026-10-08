<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a homepage link uses bare `homeUrl` instead of
 * `frontPageUrl|default(homeUrl)`.
 *
 * Project convention (header-customization.md §1): in the styleguide the
 * bootstrap sets `homeUrl` to the render base (`/styleguide/render/`) so
 * demo links built as `homeUrl ~ 'page/X'` navigate the preview iframe to
 * bare page demos. A *bare* `homeUrl` link therefore resolves to
 * `/styleguide/render/` — not a route — while in production it is the
 * homepage (`/`). The sanctioned homepage-link value is
 * `frontPageUrl|default(homeUrl)`: the styleguide bootstrap points
 * `frontPageUrl` at the homepage demo, production leaves it undefined and
 * falls back to `homeUrl`.
 *
 * Detection (AST, two forms):
 *  - **HTML attribute**: `{{ homeUrl }}` printed as the complete value of
 *    an anchor's `href` — a `PrintNode` whose expression is the bare
 *    `homeUrl` name, immediately preceded by raw text ending in
 *    `<a … href="`. The rule tracks the tail of the last `TextNode` in
 *    document order; after any `PrintNode` the tail is invalidated so
 *    `href="{{ x }}{{ homeUrl }}"` (homeUrl as a suffix, not the whole
 *    value) cannot match.
 *  - **Hash entry**: `url:` / `href:` keys in an `ArrayExpression` whose
 *    value is the bare `homeUrl` name — covers `component_button({ url:
 *    homeUrl })` and `create_attribute({ href: homeUrl })` call sites.
 *
 * Not flagged (each is either sanctioned or a different link class):
 *  - `frontPageUrl|default(homeUrl)` — a FilterExpression, not a bare name.
 *  - Concatenations `homeUrl ~ 'page/X'` — styleguide-internal demo links,
 *    functional under the render base.
 *  - Non-anchor attributes (`<link rel="canonical" href="{{ homeUrl }}">`)
 *    — the text-tail check requires an `<a` tag.
 *  - `{{ homeUrl }}` printed as content (outside an href) — displaying the
 *    URL is not linking to it.
 *
 * Both `NameExpression` and `ContextVariable` are matched — same
 * Twig-version compatibility pattern as `ProseOnRichTextRule`.
 */
final class HomeUrlLinkRule extends AbstractNodeRule
{
	private const MESSAGE = "Bare homeUrl homepage link: use frontPageUrl|default(homeUrl) — in the styleguide preview homeUrl is the render base and a bare link resolves to a non-route (header-customization.md §1)";

	/**
	 * Tail of the most recent raw-text chunk, in document order.
	 *
	 * Only the last 512 bytes matter — enough to hold an opening `<a` tag
	 * with a long class list between the tag name and `href="`.
	 */
	private string $lastTextTail = '';

	public function enterNode(Node $node, Environment $env): Node
	{
		// Fresh template — never let one file's trailing text leak into
		// the attribute-context check of the next.
		if ($node instanceof ModuleNode) {
			$this->lastTextTail = '';

			return $node;
		}

		if ($node instanceof TextNode) {
			$data = $node->getAttribute('data');
			$this->lastTextTail = \is_string($data) ? substr($data, -512) : '';

			return $node;
		}

		if ($node instanceof ArrayExpression) {
			$this->checkHashEntries($node);

			return $node;
		}

		if ($node instanceof PrintNode) {
			$expr = $node->getNode('expr');
			// Twig wraps every print in `|escape(...)` autoescape; peel
			// filter layers to reach the real expression. Peeling cannot
			// turn the sanctioned form into a false positive: for
			// `frontPageUrl|default(homeUrl)` the innermost `node` chain
			// ends at frontPageUrl (homeUrl sits in the filter *arguments*).
			while ($expr instanceof FilterExpression) {
				$expr = $expr->getNode('node');
			}
			if (
				$this->isBareHomeUrl($expr)
				&& 1 === preg_match('/<a\b[^>]*\bhref\s*=\s*["\']$/i', $this->lastTextTail)
			) {
				$this->addWarning(self::MESSAGE, $node, 'HomeUrlLink');
			}

			// Whatever was printed, the next print is no longer the
			// *complete* attribute value — invalidate the tail anchor.
			$this->lastTextTail = '';
		}

		return $node;
	}

	private function checkHashEntries(ArrayExpression $node): void
	{
		foreach ($node->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			if (!$key instanceof ConstantExpression) {
				continue;
			}

			$keyName = $key->getAttribute('value');
			if ('url' !== $keyName && 'href' !== $keyName) {
				continue;
			}

			if ($this->isBareHomeUrl($pair['value'])) {
				$this->addWarning(self::MESSAGE, $pair['value'], 'HomeUrlLink');
			}
		}
	}

	private function isBareHomeUrl(Node $expr): bool
	{
		if (!$expr instanceof NameExpression && !$expr instanceof ContextVariable) {
			return false;
		}

		return 'homeUrl' === $expr->getAttribute('name');
	}
}
