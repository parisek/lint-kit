<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when one `|resizer(...)` call passes more than one FALLBACK tuple —
 * a tuple whose `maxWidth` (third slot) is empty or absent.
 *
 * An empty `maxWidth` means "this tuple is the unconditional `<img>` fallback",
 * and the runtime emits exactly one of those (picture.md § Size tuple). Writing
 * several therefore silently discards all but one:
 *
 *     content.image|resizer(
 *         ['1440', '720', '', 'crop'],
 *         ['1024', '900', '', 'crop'],
 *         ['768',  '960', '', 'crop'],
 *     )
 *
 * That reads as a considered responsive ladder and behaves as a single image.
 * Nothing errors and nothing warns — a downstream project shipped exactly this on a
 * full-bleed hero, so phones received the 1440x720 LANDSCAPE variant to fill a
 * portrait box until a DPR contract happened to cover that component.
 *
 * Scoped to ONE `|resizer` call on purpose. `merge_resizer()` legitimately
 * combines several calls and picture.md explicitly has the desktop argument
 * carry its own fallback so the mobile-absent case still has one; the merge
 * filters non-last fallbacks itself. Verified against every consumer in the
 * original workspace: no single call passes more than one.
 *
 * Deliberately NOT flagged:
 *
 *   - A tuple that is not an array literal (a variable, a function call) —
 *     it cannot be evaluated at lint time, same stance as ResizerCropRule's
 *     `isMissingDimension`.
 *   - A `maxWidth` that is a non-constant expression, for the same reason.
 *   - A single fallback, which is required: without one there is no
 *     unconditional `<img>` at all.
 *
 * Known blind spots, all in the false-negative (safe) direction and all
 * deliberate — closing them costs more complexity than the shapes are worth,
 * none of which occur in any call site across the workspace:
 *
 *   - A spread whose contents are static (`[1200, ...[628, '']]`) is skipped
 *     with every other spread rather than resolved.
 *   - A negative or out-of-int-range key parses as NegUnary / float, not
 *     ConstantExpression, so the tuple is skipped.
 *   - `[]`, `-0` or `-0.0` as the maxWidth are runtime-empty but are not
 *     ConstantExpression nodes, so they never reach isRuntimeEmpty().
 */
final class ResizerSingleFallbackRule extends AbstractNodeRule
{
	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FilterExpression) {
			return $node;
		}

		if ('resizer' !== $node->getAttribute('name')) {
			return $node;
		}

		$fallbacks = [];
		$position = 0;
		foreach ($node->getNode('arguments') as $argument) {
			++$position;
			if (!$argument instanceof ArrayExpression) {
				continue;
			}
			if ($this->isFallbackTuple($argument)) {
				$fallbacks[] = $position;
			}
		}

		if (\count($fallbacks) > 1) {
			$this->addWarning(
				\sprintf(
					'resizer emits one fallback <img>, but %d tuples omit maxWidth (argument %s) — all but one are discarded; give the conditional ones a maxWidth breakpoint',
					\count($fallbacks),
					implode(', ', $fallbacks),
				),
				$node,
				'ResizerSingleFallback',
			);
		}

		return $node;
	}

	/**
	 * A tuple is the fallback when its `maxWidth` slot is runtime-empty —
	 * either absent entirely (`[w, h]`) or an empty literal.
	 *
	 * Returns false for anything that cannot be read with certainty, so an
	 * unusual-but-valid literal is never reported.
	 */
	private function isFallbackTuple(ArrayExpression $tuple): bool
	{
		$slots = $this->extractSlots($tuple);
		if (null === $slots) {
			return false;
		}

		// `isset($variant[2])` in the runtime: an absent slot is no media query,
		// i.e. this tuple IS the fallback.
		if (!\array_key_exists(2, $slots)) {
			return true;
		}

		$maxWidth = $slots[2];
		if (!$maxWidth instanceof ConstantExpression) {
			// A variable/expression breakpoint — not statically knowable, so
			// assume it resolves to a real value rather than risk a false report.
			return false;
		}

		return $this->isRuntimeEmpty($maxWidth->getAttribute('value'));
	}

	/**
	 * Map a tuple's INTEGER keys to their value nodes.
	 *
	 * Reads the actual key nodes rather than walking odd child indices. Twig
	 * stores an array literal as alternating key/value children, so positional
	 * walking works only while the keys happen to be 0,1,2,3 in order — a hash
	 * written `{0: w, 2: mw, 1: h}` would have its slots silently transposed,
	 * and a spread (`[w, ...rest]`) contributes children that are not a plain
	 * key/value pair at all, collapsing the apparent slot count and making a
	 * complete tuple look like a two-slot fallback.
	 *
	 * Returns null when the literal cannot be read this way — an unanalysable
	 * tuple is skipped rather than guessed at.
	 *
	 * @return array<int, Node>|null
	 */
	private function extractSlots(ArrayExpression $tuple): ?array
	{
		$slots = [];
		$count = $tuple->count();

		for ($i = 0; $i < $count; $i += 2) {
			if (!$tuple->hasNode((string) $i) || !$tuple->hasNode((string) ($i + 1))) {
				return null;
			}

			$key = $tuple->getNode((string) $i);
			if (!$key instanceof ConstantExpression) {
				return null;
			}

			// PHP normalises a numeric-string array key to an integer, so
			// `{'2': '768'}` reaches the runtime as slot 2 — accept it here too
			// rather than skip a tuple the runtime reads perfectly well.
			//
			// Only the CANONICAL decimal form is coerced, matching PHP exactly:
			// '2' and '-1' become integers, while '02', '007' and '+2' stay
			// string keys (verified against the installed PHP). A looser test
			// would map '02' onto slot 2 and miss a tuple whose slot 2 is in
			// fact absent.
			$index = $key->getAttribute('value');
			if (\is_string($index) && 1 === preg_match('/^(?:0|-?[1-9]\\d*)$/', $index)) {
				$index = (int) $index;
			}
			if (!\is_int($index)) {
				// A key PHP keeps as a string ('02', 'alt') is simply not a
				// positional slot — skip the entry and keep reading. Bailing on
				// the whole tuple here would hide the very case this catches:
				// `{'02': '768', 0: w, 1: h}` has NO slot 2 at runtime, so it is
				// a fallback.
				continue;
			}

			$value = $tuple->getNode((string) ($i + 1));

			// A spread element (`[w, ...rest]`) is stored as an ordinary
			// key/value pair whose VALUE is a SpreadUnary node (verified against
			// the installed Twig: key stays a plain integer ConstantExpression,
			// and there is no `spread` attribute to test). Its contents are
			// unknown at lint time, so the tuple's real slot count is unknown
			// too — treating it as a short tuple would report a complete, valid
			// tuple as a fallback.
			if ($value instanceof SpreadUnary) {
				return null;
			}

			$slots[$index] = $value;
		}

		return $slots;
	}

	/**
	 * Mirror the runtime's own test, which is `!empty($variant[2])`
	 * (Helpers::resizeImage) — so string '0', false and 0.0 count as empty
	 * exactly as they do at render time. A stricter check would miss real
	 * duplicate fallbacks.
	 */
	private function isRuntimeEmpty(mixed $literal): bool
	{
		return '' === $literal
			|| '0' === $literal
			|| 0 === $literal
			|| 0.0 === $literal
			|| false === $literal
			|| null === $literal
			|| [] === $literal;
	}
}
