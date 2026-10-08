<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a translation call hard-codes a stale theme name
 * in its `context` or `domain` slot — i.e. a slot containing a string
 * literal that doesn't match the project's styleguide `project.slug`.
 *
 * Project convention (see `twig.md` (tailwind-base, `.claude/rules/theme/twig.md`))
 * is to pass the theme name into both the context and the domain slot of
 * `_x()` / `_ex()` / `esc_html_x()` / `esc_attr_x()` and into the domain
 * slot of `__()` / `_e()` / `esc_html__()` / `esc_html_e()` / `esc_attr__()`
 * / `esc_attr_e()` / `_n()` / `_nx()`. After a sync from `tailwind-base`
 * into a downstream project (e.g. `my-theme`), every literal `'tailwind-base'`
 * arg becomes stale and silently ships in the wrong text-domain unless
 * caught.
 *
 * Constructor injection keeps the rule reusable across projects:
 *   new TranslationThemeNameRule($themeName)
 * with `$themeName` parsed from `styleguide.yaml` `project.slug` in `config.php`.
 *
 * Detection (AST):
 *  - Match `FunctionExpression` whose name is in `TRANSLATION_FUNCTIONS`.
 *  - For each non-null slot index, fetch the positional argument node.
 *  - If the arg is a string `ConstantExpression` and its value !== `$themeName`
 *    → fire one warning per offending slot (so `_x('t','wrong','wrong')`
 *    produces 2 warnings, one for context, one for domain).
 *
 * Skipped silently:
 *  - Variable / concat / function-call args (dynamic values can't be
 *    statically validated — caller is doing something the rule can't reason about).
 *  - Calls with fewer args than the highest required index (malformed call;
 *    a runtime PHP error will surface it; not the rule's concern).
 *  - Functions outside the dispatch table (`gettext`, custom helpers, etc.).
 *
 * Known limitations:
 *  - Variable args bypass detection by design. Document any project that
 *    relies on `_x($text, $context_var, $domain)` so reviewers know the
 *    rule isn't airtight against bypass.
 *  - PHP-side translation calls in `templates/<cpt>/<cpt>.php` are out of
 *    scope — that requires a PHP CodeSniffer sniff, separate doctrine track.
 *  - Context-vs-hint semantics: the rule enforces the project's "theme name
 *    in both slots" convention, not WordPress's looser "context = translator
 *    hint" semantics. Use Layer A disable for legitimate translator hints.
 */
final class TranslationThemeNameRule extends AbstractNodeRule
{
	/**
	 * Translation function dispatch table. `_x`, `__`, `_n` and `_nx` exist in WordPress, in Drupal and
	 * in the styleguide; the `esc_*` names exist only in WordPress and are harmless elsewhere.
	 *
	 * Values are 0-indexed positions in the `FunctionExpression::arguments` Node.
	 * `null` means the slot does not exist for this function (e.g. `__()` has
	 * no context slot, only domain).
	 *
	 * The displayed `arg #<N>` in the warning message is `index + 1` (1-based).
	 */
	private const TRANSLATION_FUNCTIONS = [
		'__'         => ['context' => null, 'domain' => 1],
		'_e'         => ['context' => null, 'domain' => 1],
		'esc_html__' => ['context' => null, 'domain' => 1],
		'esc_html_e' => ['context' => null, 'domain' => 1],
		'esc_attr__' => ['context' => null, 'domain' => 1],
		'esc_attr_e' => ['context' => null, 'domain' => 1],
		'_x'         => ['context' => 1, 'domain' => 2],
		'_ex'        => ['context' => 1, 'domain' => 2],
		'esc_html_x' => ['context' => 1, 'domain' => 2],
		'esc_attr_x' => ['context' => 1, 'domain' => 2],
		'_n'         => ['context' => null, 'domain' => 3],
		'_nx'        => ['context' => 3, 'domain' => 4],
	];

	public function __construct(private readonly string $themeName)
	{
	}

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FunctionExpression) {
			return $node;
		}

		$name = $node->getAttribute('name');
		if (!isset(self::TRANSLATION_FUNCTIONS[$name])) {
			return $node;
		}

		$slots = self::TRANSLATION_FUNCTIONS[$name];
		$arguments = $node->getNode('arguments');

		foreach (['context', 'domain'] as $slot) {
			$index = $slots[$slot];
			if (null === $index) {
				continue;
			}

			$key = (string) $index;
			if (!$arguments->hasNode($key)) {
				// Malformed or partial call (fewer args than expected). Skip.
				continue;
			}

			$arg = $arguments->getNode($key);
			if (!$arg instanceof ConstantExpression) {
				// Variable, concat, function call — dynamic, can't validate. Skip.
				continue;
			}

			$value = $arg->getAttribute('value');
			if (!\is_string($value)) {
				continue;
			}

			if ($value === $this->themeName) {
				continue;
			}

			$argNumber = $index + 1;
			$this->addWarning(
                "{$name}() {$slot} arg #{$argNumber}: expected '{$this->themeName}', got '{$value}'. Rewrite to match project.slug from styleguide.yaml.",
				$arg,
				'TranslationThemeName',
			);
		}

		return $node;
	}
}
