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
 * Warns when a multi-entry `class:` array inside `create_attribute({...})`
 * packs two or more of its top-level entries onto the same source line.
 *
 * Owner doctrine (`create-attribute.md` § Formatting Multi-Entry `class:`
 * Arrays): a `class:` array with MULTIPLE entries must ALWAYS be split one
 * entry per line. A single-line multi-entry array —
 * `class: ['w-full', cond ? 'a' : 'b', 'relative'],` — is the antipattern
 * this rule exists to catch. Single-entry arrays are unaffected (that's
 * `CreateAttributeClassArrayRule`'s concern, not this one).
 *
 * Detection (AST):
 *  - Match `FunctionExpression` whose name is `create_attribute`.
 *  - First positional argument must be an `ArrayExpression` (the hash).
 *  - Locate the `class` hash entry; value must be an `ArrayExpression`.
 *  - Collect the top-level entries (`getKeyValuePairs()`'s `value` nodes).
 *  - Fewer than 2 entries → skip (nothing to split across lines).
 *  - Walk the entries' `getTemplateLine()` in order; if any two entries
 *    report the same line number, fire once on the second of that pair.
 *
 * Skipped silently:
 *  - 0 or 1 top-level entries.
 *  - Every entry already sits on its own line (the compliant shape).
 *
 * Only the entries' own lines are compared — an entry sharing the line
 * with the opening `class: [` bracket (but not with another entry) does
 * not, by itself, trigger this rule; the owner's stated antipattern is
 * entries sharing a line WITH EACH OTHER, not with the bracket.
 */
final class CreateAttributeClassMultilineRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'create_attribute';

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

		$class = $this->findHashEntry($hash, 'class');
		if (!$class instanceof ArrayExpression) {
			return $node;
		}

		$entries = [];
		foreach ($class->getKeyValuePairs() as $pair) {
			$entries[] = $pair['value'];
		}

		if (\count($entries) < 2) {
			return $node;
		}

		$seenLines = [];
		foreach ($entries as $entry) {
			$line = $entry->getTemplateLine();
			if (isset($seenLines[$line])) {
				$this->addWarning(
					'Multi-entry create_attribute class: array must have one entry per line — split each array item onto its own line (trailing comma, closing ] on its own line) per create-attribute.md § Formatting Multi-Entry class: Arrays',
					$entry,
					'CreateAttributeClassMultiline',
				);

				return $node;
			}
			$seenLines[$line] = true;
		}

		return $node;
	}

	/**
	 * Walk the alternating key/value children of an ArrayExpression and return
	 * the value node for `$key` (if the key is a constant string). Returns null
	 * for missing keys, dynamic keys, or non-array nodes.
	 */
	private function findHashEntry(ArrayExpression $hash, string $key): ?Node
	{
		foreach ($hash->getKeyValuePairs() as $pair) {
			$pairKey = $pair['key'];
			if (!$pairKey instanceof ConstantExpression) {
				continue;
			}
			if ($pairKey->getAttribute('value') === $key) {
				return $pair['value'];
			}
		}

		return null;
	}
}
