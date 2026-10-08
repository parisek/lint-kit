<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a `component_picture({…})` call passes raw CMS image data as
 * `image:` without sending it through `|resizer(…)` first.
 *
 * `component_picture` renders what it receives. It does not resize. A raw
 * CMS entry (`item.image`, `content.awards`) holds the ORIGINAL upload, so
 * every visitor downloads the full-size file: no AVIF/WebP variant, no
 * `srcset`, no per-breakpoint size. The failure is SILENT. The page renders
 * correctly, the build passes, no linter objects, and the cost shows up only
 * in an LCP or page-weight report later (picture.md § "Where to pipe").
 *
 * Detection (AST):
 *  - Match `FunctionExpression` named `component_picture`.
 *  - The first argument must be an `ArrayExpression` (the hash literal).
 *  - Find the `image` key. Peel every `FilterExpression` layer off its value.
 *    If any layer is `resizer`, the value is fine.
 *  - Warn when the innermost expression is a property access
 *    (`GetAttrExpression`, e.g. `item.image`) and no layer was `resizer`.
 *    A filter chain without `resizer` (`item.image|first`) warns the same
 *    way: the filter does not change the fact that the file is unresized.
 *
 * SVG needs no carve-out. `Resizer::resizer()` (timber-kit) tests the source
 * MIME with `canDecode()`. SVG is not decodable, so it returns the original
 * entry (`src`, `width`, `height`, `alt`) unchanged. Piping an SVG through
 * `|resizer` is therefore harmless, and the correct habit stays uniform:
 * pipe every CMS image, and the resizer decides.
 *
 * Deliberately NOT inspected (each is silent by design):
 *  - `merge_resizer(…)`, `placeholder()` and any other function call: the
 *    value is built by a function that owns the shape.
 *  - An `ArrayExpression` literal (`[{ src: …, width: …, height: … }]`): a
 *    static asset with explicit dimensions, for example a store badge.
 *  - A bare variable (`{% set hero = … %}` then `image: hero`): the rule
 *    cannot follow the assignment. The `{% set %}` line holds the pipe, and
 *    that is where a human should look.
 *  - A method call (`post.thumbnail()`): the return shape is unknown.
 *  - `?:`, `??`, `|default(…)` and `a ? b : c`: the branches usually differ on purpose
 *    (`item.image ?: placeholder()`). Deciding which branch ships needs data
 *    flow. A wrong guess would flag correct code, so the rule skips them.
 *
 * A call site that must pass the original on purpose silences the rule with
 * `{# twig-cs-fixer-disable-next-line PictureResizer — <reason> #}` placed
 * directly above the `image:` line.
 */
final class PictureResizerRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'component_picture';

	private const KEY = 'image';

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

		foreach ($hash->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			if (!$key instanceof ConstantExpression || self::KEY !== $key->getAttribute('value')) {
				continue;
			}

			if ($this->isRawCmsImage($pair['value'])) {
				$this->addWarning(
					"component_picture receives raw CMS image data without |resizer — a raster image ships "
					. "as the original file (no AVIF, no srcset). Pipe it: image: …|resizer([…]). "
					. "An SVG passes through unchanged, so the pipe costs nothing there.",
					$node, // the call, not the value: a Layer A comment cannot sit inside the hash, so it must sit above the call line
					'PictureResizer',
				);
			}
		}

		return $node;
	}

	/**
	 * True when the value is a property access reached through zero or more
	 * filters, none of which is `resizer`.
	 */
	private function isRawCmsImage(Node $expr): bool
	{
		while ($expr instanceof FilterExpression) {
			if ('resizer' === $expr->getAttribute('name')) {
				return false;
			}
			$expr = $expr->getNode('node');
		}

		return $expr instanceof GetAttrExpression && 'method' !== $expr->getAttribute('type');
	}
}
