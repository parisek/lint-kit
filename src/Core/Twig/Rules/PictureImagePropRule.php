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
 * Warns when a `component_picture({…})` call carries a key that the
 * component only ever reads from INSIDE its `image:` entries.
 *
 * `picture.twig` splits its inputs cleanly: `alt`, `src`, `width`,
 * `height`, `title`, `type` and `media` are read per image entry
 * (`image.alt`, `image.src`, …), while `image_classes`, `loading`,
 * `wrapper_*` and friends are read from the top level (`content.*`).
 * The two sets do not overlap, so a top-level occurrence of an
 * entry-only key is always a mistake — and a SILENT one: the value is
 * simply never read, no error, no warning.
 *
 * That silence is the reason this rule exists. In one downstream project
 * the header logo passed `alt:` as a sibling of `image:`; it rendered
 * `alt=""`, and because the logo is the only content of a link to the
 * homepage, the link had no accessible name — 42 axe `link-name`
 * violations, one per render that shows the header, invisible until an
 * accessibility scan finally ran (tailwind-base#400).
 *
 * Detection (AST):
 *  - Match `FunctionExpression` named `component_picture`.
 *  - First positional argument must be an `ArrayExpression` (the hash
 *    literal). A pre-built variable is out of scope — nothing to inspect.
 *  - Warn once per entry-only key found at the top level, pointing at the
 *    value so the reported line is the offending one.
 *
 * Deliberately NOT inspected: the `image:` value itself. Almost every real
 * call site passes a pipe chain (`content.image|resizer([…])`) rather than
 * a literal array, so a rule that needed to see inside the entries would
 * skip the majority of the catalogue. It never has to: the question is
 * only whether an entry-only key sits at the WRONG level, which the outer
 * hash answers on its own.
 *
 * Components that take their own top-level `alt` prop and nest it into an
 * image array they build are unaffected — the rule matches only calls
 * literally named `component_picture`, never the wrapper's own signature.
 */
final class PictureImagePropRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'component_picture';

	/**
	 * Keys `picture.twig` reads as `image.<key>` — i.e. per entry, never
	 * from the top level. Derived from the template, not hand-picked: keep
	 * in step with it if the component's contract ever changes.
	 */
	private const IMAGE_ENTRY_KEYS = ['alt', 'height', 'media', 'src', 'title', 'type', 'width'];

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
			if (!$key instanceof ConstantExpression) {
				continue; // dynamic key — nothing to name in the message
			}

			$name = $key->getAttribute('value');
			if (!\is_string($name) || !\in_array($name, self::IMAGE_ENTRY_KEYS, true)) {
				continue;
			}

			$this->addWarning(
				"component_picture reads '{$name}' from inside each image: entry, not from the top level — "
				. "it is silently dropped here; move it into the image array",
				$pair['value'],
				'PictureImageProp',
			);
		}

		return $node;
	}
}
