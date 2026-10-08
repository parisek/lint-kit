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
 * Warns when a single `class` string inside `create_attribute({ class: … })`
 * exceeds a character threshold — the same length concern `LongClassListRule`
 * enforces on raw `class="…"` HTML attributes, applied to the array form.
 *
 * Why this is a separate rule: `LongClassListRule` is the *escape* for long
 * HTML class attributes — it tells authors to move the class list into
 * `create_attribute({ class: [...] })`. But once inside `create_attribute`,
 * a 240-char single string is invisible to that token-based rule (it scans
 * `class="…"` literals, not Twig string expressions). The array form's whole
 * value is line-by-line readability, which a single giant entry defeats. This
 * rule closes that blind spot: a long entry should be split into several
 * shorter `class: [...]` lines grouped by concern (layout / pseudo / state).
 *
 * Detection (AST):
 *  - Match `FunctionExpression` whose name is `create_attribute`.
 *  - First positional argument must be an `ArrayExpression` (the hash).
 *  - Locate the `class` hash entry. Its value may be either:
 *      - an `ArrayExpression` → check every `ConstantExpression` string element;
 *      - a bare `ConstantExpression` string (`class: 'long …'`) → check it.
 *  - Fire for any string whose length > `$maxLength` AND that contains a
 *    space (i.e. it's multiple classes that CAN be split across lines).
 *
 * Skipped silently (deliberate):
 *  - A single unsplittable token with no internal space — e.g. an arbitrary
 *    value `bg-[linear-gradient(…)]` (Tailwind uses `_`, never a raw space).
 *    Splitting it is impossible, so nagging would be noise.
 *  - Non-`ConstantExpression` entries (conditionals `x ? 'a' : 'b'`, filters,
 *    concatenations) — same reasoning as `CreateAttributeClassArrayRule`.
 *  - `class:` values that aren't a string or array of strings.
 *
 * Configurable: `$maxLength` constructor argument (default 120, matching
 * `LongClassListRule`). Disable per-line / per-region with the standard
 * `{# twig-cs-fixer-disable-next-line CreateAttributeClassLength #}` doctrine.
 */
final class CreateAttributeClassLengthRule extends AbstractNodeRule
{
	private const FUNCTION_NAME = 'create_attribute';

	private const DEFAULT_MAX_LENGTH = 120;

	public function __construct(private readonly int $maxLength = self::DEFAULT_MAX_LENGTH)
	{
	}

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
		if (null === $class) {
			return $node;
		}

		if ($class instanceof ArrayExpression) {
			foreach ($class->getKeyValuePairs() as $pair) {
				$this->checkEntry($pair['value']);
			}

			return $node;
		}

		// `class: 'long single string'` — not wrapped in an array.
		$this->checkEntry($class);

		return $node;
	}

	/**
	 * Flag a single class-list string node when it's too long AND splittable
	 * (contains a space). No-op for non-string nodes and short / single-token
	 * strings.
	 */
	private function checkEntry(Node $value): void
	{
		if (!$value instanceof ConstantExpression) {
			return;
		}

		$class = $value->getAttribute('value');
		if (!\is_string($class)) {
			return;
		}

		$length = \strlen($class);
		if ($length <= $this->maxLength) {
			return;
		}

		// A single unsplittable token (arbitrary value, no whitespace) can't be
		// broken across lines — only nag when there are multiple classes.
		if (!str_contains($class, ' ')) {
			return;
		}

		$this->addWarning(
			sprintf(
				'class string is %d chars long (limit %d) — split into multiple `class: [...]` entries grouped by concern — see twig-patterns.md § HTML Attributes with create_attribute',
				$length,
				$this->maxLength,
			),
			$value,
			'CreateAttributeClassLength',
		);
	}

	/**
	 * Walk the alternating key/value children of an ArrayExpression and return
	 * the value node for `$key` (when the key is a constant string). Returns
	 * null for missing keys, dynamic keys, or non-array nodes.
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
