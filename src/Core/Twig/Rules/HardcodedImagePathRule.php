<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when an `src` hash entry holds a literal absolute path instead
 * of `templateUrl ~ '/...'`.
 *
 * Themes get installed under `wp-content/themes/<name>/`, so a literal
 * `src: '/images/foo.jpg'` resolves at site root and 404s. The convention
 * is `src: templateUrl ~ '/images/foo.jpg'` — the framework prepends the
 * theme base.
 *
 * AST-aware port of the Python `hardcoded-image-path` regex. Walks every
 * `ArrayExpression`, finds entries keyed `src`, and fires when the value
 * is a `ConstantExpression` whose string starts with `/`. Anything that
 * isn't a bare string constant — `BinaryConcat` (i.e. `templateUrl ~ '…'`),
 * variables, function calls — is skipped.
 *
 * Scope difference from the Python check: the regex only fired in files
 * whose name contains `styleguide`. The AST port is universal — production
 * templates don't carry literal `src:` hashes (they use `content.image`
 * from CMS), so widening costs nothing and catches stray hardcoded paths
 * anywhere in the codebase.
 */
final class HardcodedImagePathRule extends AbstractNodeRule
{
	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ArrayExpression) {
			return $node;
		}

		foreach ($node->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			$value = $pair['value'];

			if (!$key instanceof ConstantExpression || 'src' !== $key->getAttribute('value')) {
				continue;
			}

			if (!$value instanceof ConstantExpression) {
				// BinaryConcat (templateUrl ~ '…'), variables, calls — all OK.
				continue;
			}

			$path = $value->getAttribute('value');
			if (!\is_string($path) || '' === $path || '/' !== $path[0]) {
				continue;
			}

			$this->addWarning(
				"Use 'templateUrl ~ \\'/images/...\\'' instead of hardcoded path",
				$value,
				'HardcodedImagePath',
			);
		}

		return $node;
	}
}
