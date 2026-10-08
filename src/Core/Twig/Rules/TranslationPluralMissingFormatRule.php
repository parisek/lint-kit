<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a plural translation carrying a printf placeholder is not piped
 * through `|format(…)` — `_nx('%s review', '%s reviews', n, …)` with nothing
 * substituting the `%s`.
 *
 * ## Why this is not obvious, and why a linter has to say it
 *
 * The three runtimes these templates render in disagree about whether the
 * plural helpers substitute the number:
 *
 * | | WordPress | Drupal | styleguide |
 * | --- | --- | --- | --- |
 * | `_n`  | returns the form | substitutes (`formatPlural`) | returns the form |
 * | `_nx` | returns the form | substitutes | substitutes (`sprintf`) |
 *
 * So the identical call renders `5 reviews` on Drupal and in the styleguide,
 * and a literal `%s reviews` on a WordPress site. Nothing errors, nothing logs;
 * the styleguide preview looks right either way. Measured 2026-08-12 across nine
 * downstream projects: every Drupal call site omits `|format`, every WordPress
 * one includes it — the same string written two opposite ways, and neither
 * author was wrong for their own runtime.
 *
 * **`|format(n)` is the portable spelling — with one precondition.** Where the
 * helper already substituted, the placeholder is gone and `sprintf` has nothing
 * left to do; where it did not, `|format` performs the substitution. The same
 * template is then right in all three, which is why this rule asks for `|format`
 * rather than asking anyone to pick a runtime.
 *
 * The precondition, and it is not a footnote: **every literal `%` in the
 * translated string must be escaped `%%`.** `sprintf` reads `%` in its format
 * string whether or not an earlier pass already substituted — `sprintf('100%
 * off', 5)` returns `1005ff`, because `% o` is consumed as an octal conversion.
 * Verified, not assumed. This bites hardest on a Drupal template that never
 * needed `|format` before: its catalogue was written for a runtime that
 * substituted on its own, so nothing ever forced the escaping. **Check the
 * translations, not only the msgid, before adding the filter** — the msgid is
 * all this rule can see, and it is not where the hazard lives.
 *
 * ## Detection (AST)
 *
 *  - Match a `FunctionExpression` named `_n` / `_nx` / `_nt` / `_nxt`.
 *  - Fire only when a **literal** singular or plural argument carries a printf
 *    conversion (`%s`, `%d`, `%1$s`, `%.2f`, `%'x10d`, …). `%%` is an escaped
 *    percent and is stripped before the check.
 *  - Do not fire when a `|format(…)` filter has the call anywhere beneath it.
 *    The filter is seen first (`enterNode` is pre-order), so it marks every
 *    plural call in its subtree before any of them is visited.
 *
 * Deliberately narrow:
 *  - A dynamic first/second argument (variable, concatenation, another call)
 *    never fires — the placeholder cannot be read without evaluating it.
 *  - `_nx(…)|typography` is left to `TranslationTypographyFilterRule`, which
 *    already names the `…t` helper; no need to report the same line twice.
 *  - Plural strings with no placeholder never fire. Passing the count in the
 *    surrounding markup (`{{ n }} {{ _nx('day', 'days', n, …) }}`) is a valid
 *    shape, and so is handing the forms to JavaScript for client-side selection.
 */
final class TranslationPluralMissingFormatRule extends AbstractNodeRule
{
	/**
	 * Plural translation functions. All four take singular at index 0 and
	 * plural at index 1; the count and context/domain slots differ but do not
	 * matter here.
	 */
	private const PLURAL_FUNCTIONS = ['_n', '_nx', '_nt', '_nxt'];

	/**
	 * A printf conversion specification: optional positional (`%1$s`), flags,
	 * a custom padding character (`%'x10d` — the char after the quote is part
	 * of the spec and must be consumed, or the reported text truncates), width
	 * and precision. `%%` is stripped before matching, so an escaped percent
	 * cannot trigger it.
	 */
	private const PLACEHOLDER_PATTERN = '/%(?:\d+\$)?[-+ 0]*(?:\'.)?\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

	/**
	 * `spl_object_id()` of every plural call already wrapped in `|format(…)`.
	 *
	 * Populated when the filter is entered, read when the call inside it is.
	 * Twig's node traversal is pre-order, so the parent filter is always
	 * visited before the function it wraps.
	 *
	 * @var array<int, true>
	 */
	private array $covered = [];

	public function enterNode(Node $node, Environment $env): Node
	{
		if ($node instanceof FilterExpression) {
			$this->markCoveredByFormat($node);

			return $node;
		}

		if (!$node instanceof FunctionExpression) {
			return $node;
		}

		$function = $node->getAttribute('name');
		if (!\is_string($function) || !\in_array($function, self::PLURAL_FUNCTIONS, true)) {
			return $node;
		}

		if (isset($this->covered[spl_object_id($node)])) {
			return $node;
		}

		$placeholder = $this->firstLiteralPlaceholder($node);
		if (null === $placeholder) {
			return $node;
		}

		$this->addWarning(
			\sprintf(
				'%s(…) carries the placeholder %s but is not piped through |format(…) — it renders literally where the plural helper '
				. 'returns the form and never substitutes (WordPress `_n` and `_nx`, the styleguide `_n`). Append |format(<count>), then check the '
				. 'TRANSLATIONS escape every literal %% as %%%% — sprintf reads them even where the runtime already '
				. 'substituted, and turns "100%% off" into "1005ff".',
				$function,
				$placeholder,
			),
			$node,
			'TranslationPluralMissingFormat',
		);

		return $node;
	}

	/**
	 * Record every plural call somewhere beneath a `|format(…)` filter.
	 *
	 * Walks the whole operand subtree rather than testing the immediate child.
	 * Checking only the direct operand flagged two correct shapes:
	 * `_nx(…)|trim|format(n)` (another filter in between) and
	 * `(_nx(…) ~ '!')|format(n)` (wrapped in a binary expression). Both hand
	 * the placeholder to `format` perfectly well.
	 *
	 * The walk starts at the operand, **not** at the `FilterExpression` itself,
	 * so `format`'s own arguments are not covered. That distinction is load-
	 * bearing: in `'outer %s'|format(_nx('%s a', '%s b', n, …))` the plural is a
	 * replacement *value*, its own `%s` is never substituted by that call, and
	 * it must still warn.
	 */
	private function markCoveredByFormat(FilterExpression $node): void
	{
		if ('format' !== $node->getAttribute('name')) {
			return;
		}

		$this->markPluralCallsIn($node->getNode('node'));
	}

	/**
	 * Mark every plural call in this subtree as covered.
	 */
	private function markPluralCallsIn(Node $node): void
	{
		if ($node instanceof FunctionExpression) {
			$function = $node->getAttribute('name');
			if (\is_string($function) && \in_array($function, self::PLURAL_FUNCTIONS, true)) {
				$this->covered[spl_object_id($node)] = true;
			}
		}

		foreach ($node as $child) {
			if ($child instanceof Node) {
				$this->markPluralCallsIn($child);
			}
		}
	}

	/**
	 * The first printf placeholder found in any literal argument, or null when
	 * none carries one.
	 *
	 * Scans **every** argument rather than positions 0 and 1. Twig keys named
	 * arguments by parameter name (`_n(single: '%s x', plural: '%s y', …)`), so
	 * an index-based read misses them entirely and the call goes unwarned —
	 * a false negative, and a silent one. Reading every literal costs nothing:
	 * the remaining arguments are the count (not a string), the context and the
	 * domain, and a placeholder in a domain would be a defect of its own.
	 */
	private function firstLiteralPlaceholder(FunctionExpression $node): ?string
	{
		foreach ($node->getNode('arguments') as $argument) {
			if (!$argument instanceof ConstantExpression) {
				// Variable, concatenation, nested call — not readable here.
				continue;
			}

			$value = $argument->getAttribute('value');
			if (!\is_string($value)) {
				continue;
			}

			// Strip escaped percents first so `100%% off` cannot match.
			$stripped = str_replace('%%', '', $value);
			if (preg_match(self::PLACEHOLDER_PATTERN, $stripped, $matches)) {
				return $matches[0];
			}
		}

		return null;
	}
}
