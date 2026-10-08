<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a translation call is piped straight through `|typography`
 * (`_x('…', …)|typography`) and points to the typography-aware helper that
 * folds both steps into a single call (`_xt('…', …)`).
 *
 * Convention: see `twig.md` § Typography-aware translations (tailwind-base, `.claude/rules/theme/twig.md`).
 * The helpers `_xt` / `__t` / `_nt` / `_nxt` translate AND apply `|typography`,
 * so the explicit `_x(…)|typography` form is redundant for visible prose. The
 * four signatures match the WordPress originals 1:1 and ship across all three
 * CMS targets (styleguide preview, Timber, Drupal), so the rewrite is portable.
 *
 * Detection (AST):
 *  - Match a `FilterExpression` whose filter name is `typography`.
 *  - If the node it filters is a `FunctionExpression` named one of
 *    `_x` / `__` / `_n` / `_nx`, fire one warning naming the helper to use.
 *
 * Deliberately narrow — only the deterministic, zero-false-positive case:
 *  - `_x(…)|format(year)|typography` does NOT fire: the typography filter's
 *    operand is the `format` filter, not the translation call. That chain is
 *    the *correct* form for `|format` placeholder strings (typography last),
 *    documented in twig.md.
 *  - A bare `_x('Close', …)` in an `aria-label` / attribute does NOT fire: no
 *    `|typography` is present, and whether such a string *should* become `_xt`
 *    is a prose-vs-technical judgment a linter can't make reliably (it depends
 *    on whether the value is displayed, an a11y label, or handed to a macro
 *    that already typographies it). That stays the convention doc's job.
 */
final class TranslationTypographyFilterRule extends AbstractNodeRule
{
	/**
	 * Plain translation function => its typography-aware twin.
	 */
	private const HELPER_MAP = [
		'_x'  => '_xt',
		'__'  => '__t',
		'_n'  => '_nt',
		'_nx' => '_nxt',
	];

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FilterExpression) {
			return $node;
		}

		if ('typography' !== $node->getAttribute('name')) {
			return $node;
		}

		$operand = $node->getNode('node');
		if (!$operand instanceof FunctionExpression) {
			return $node;
		}

		$function = $operand->getAttribute('name');
		if (!\is_string($function) || !isset(self::HELPER_MAP[$function])) {
			return $node;
		}

		$helper = self::HELPER_MAP[$function];
		$this->addWarning(
			\sprintf(
				'%s(…)|typography is redundant — use %s(…) instead (translates and applies typography in one call). See twig.md § Typography-aware translations.',
				$function,
				$helper,
			),
			$node,
			'TranslationTypographyFilter',
		);

		return $node;
	}
}
