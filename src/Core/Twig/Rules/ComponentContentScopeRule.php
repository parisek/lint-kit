<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use TwigCsFixer\Rules\AbstractRule;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\Tokens;

/**
 * Warns when a component template reads a variable that was never passed to it.
 *
 * A component receives its input as `content`. Everything else it reads comes
 * from whatever context the caller happened to have, which makes the component
 * silently caller-dependent: it renders plausible markup that changes meaning
 * depending on where it is used.
 *
 * This used to be enforced by the language. `{% include '@component/x/x.twig'
 * with { content: … } only %}` restricts the child's scope to exactly what was
 * passed, so an unpassed variable is simply undefined. The project moved to
 * `component_x({ … })`, which renders via
 * `array_merge($context, ['content' => $content])` and therefore always merges
 * the caller's context — the `only` guarantee is gone, and nothing replaced it.
 * That is what this rule replaces it with, and it covers the call sites that
 * never had the guarantee in the first place.
 *
 * Deliberately conservative — it reports a name only when every cheaper
 * explanation is exhausted:
 *
 *  - `content` and anything under it
 *  - names bound by the template itself: `{% set %}`, `{% for %}` targets,
 *    `{% macro %}` parameters, `{% import … as %}` / `{% from … import %}`
 *  - the project's Twig globals (see ALLOWED_GLOBALS)
 *  - function calls, filters, tests, and property access — a name after `.`,
 *    after `|`, after `is`, or before `(` is not a variable read
 *  - Twig's own keywords and literals
 *
 * Scope is `templates/component/**` only. Page templates and styleguide
 * fixtures are composition sites, not components, and legitimately read the
 * variables they then pass down.
 */
final class ComponentContentScopeRule extends AbstractRule
{
	/**
	 * Twig globals this project registers, which a component may read directly.
	 *
	 * `templateUrl`, `homeUrl`, `frontPageUrl` and `langcode` come from the
	 * styleguide bootstrap's `twig_context` and from the CMS bootstrap; they are
	 * ambient by design. Extend this list only when a genuinely global value is
	 * added on BOTH bootstraps — a value that exists in only one of them is a
	 * per-project variable and belongs in `content`.
	 */
	private const ALLOWED_GLOBALS = [
		'templateUrl',
		'homeUrl',
		'frontPageUrl',
		'langcode',
		'component',
		'attributes',
		'_self',
		'_context',
		'_charset',
		'loop',
	];

	/** Names that are Twig syntax rather than variable reads. */
	private const KEYWORDS = [
		'if', 'else', 'elseif', 'endif', 'for', 'endfor', 'in', 'not', 'and',
		'or', 'is', 'set', 'endset', 'block', 'endblock', 'macro', 'endmacro',
		'import', 'from', 'as', 'with', 'only', 'extends', 'include', 'embed',
		'endembed', 'use', 'apply', 'endapply', 'autoescape', 'endautoescape',
		'verbatim', 'endverbatim', 'spaceless', 'endspaceless', 'do', 'flush',
		'true', 'false', 'null', 'none', 'TRUE', 'FALSE', 'NULL', 'NONE',
		'defined', 'empty', 'even', 'odd', 'iterable', 'sameas', 'divisibleby',
		'parent', 'matches', 'starts', 'ends', 'same',
	];

	private ?string $cachedFilename = null;

	/** @var array<string, true> */
	private array $bound = [];

	/** @var array<string, true> */
	private array $reported = [];

	private bool $inScope = false;

	protected function process(int $tokenIndex, Tokens $tokens): void
	{
		$token = $tokens->get($tokenIndex);
		if (Token::NAME_TYPE !== $token->getType()) {
			return;
		}

		$this->loadSource($token->getFilename());
		if (!$this->inScope) {
			return;
		}

		$name = $token->getValue();

		if ('content' === $name
			|| in_array($name, self::ALLOWED_GLOBALS, true)
			|| in_array($name, self::KEYWORDS, true)
			|| isset($this->bound[$name])
			|| isset($this->reported[$name])
		) {
			return;
		}

		// A name reached through `.`, `|` or `is` is a property, filter or test,
		// not a variable. A name followed by `(` is a function call — the
		// project registers component_*, page_*, _x(), placeholder() and more,
		// and none of them are variables.
		$previous = $this->neighbour($tokens, $tokenIndex, -1);
		if (null !== $previous && in_array($previous->getValue(), ['.', '|', 'is'], true)) {
			return;
		}

		$next = $this->neighbour($tokens, $tokenIndex, 1);
		if (null !== $next && '(' === $next->getValue()) {
			return;
		}

		$this->reported[$name] = true;
		$this->addWarning(
			sprintf(
				'Component reads "%s", which is not passed to it — it comes from whatever context the caller had. '
					. 'Pass it through `content`, or add it to ALLOWED_GLOBALS if it is genuinely ambient.',
				$name,
			),
			$token,
			'ComponentContentScope',
		);
	}

	/** Nearest token in $direction that is not whitespace or a newline. */
	private function neighbour(Tokens $tokens, int $index, int $direction): ?Token
	{
		for ($i = $index + $direction; $tokens->has($i); $i += $direction) {
			$type = $tokens->get($i)->getType();
			if (Token::WHITESPACE_TYPE !== $type && Token::EOL_TYPE !== $type) {
				return $tokens->get($i);
			}
		}

		return null;
	}

	private function loadSource(string $filename): void
	{
		if ($filename === $this->cachedFilename) {
			return;
		}

		$this->cachedFilename = $filename;
		$this->bound = [];
		$this->reported = [];

		// Components only. A page template or a styleguide fixture composes
		// components and legitimately holds the variables it passes down.
		$this->inScope = (bool) preg_match('#/component/[^/]+/[^/]+\.twig$#', $filename)
			&& !str_contains(basename($filename), 'styleguide');

		if (!$this->inScope) {
			return;
		}

		$source = @file_get_contents($filename);
		if (false === $source) {
			$this->inScope = false;

			return;
		}

		$this->collectBoundNames($source);
	}

	/**
	 * Names the template binds itself, which are therefore not caller context.
	 */
	private function collectBoundNames(string $source): void
	{
		$patterns = [
			// {% set foo = … %} and {% set foo %}…{% endset %}
			'/\{%-?\s*set\s+([a-zA-Z_][a-zA-Z0-9_]*)/',
			// {% for item in … %} and {% for key, item in … %}
			'/\{%-?\s*for\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:,\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*)?in\b/',
			// {% import '…' as icons %} / {% from '…' import a, b %}
			'/\{%-?\s*import\s+[^%]*?\bas\s+([a-zA-Z_][a-zA-Z0-9_]*)/',
			'/\{%-?\s*from\s+[^%]*?\bimport\s+([a-zA-Z0-9_,\s]+)/',
			// {% macro name(a, b) %} — the parameters, not the macro name
			'/\{%-?\s*macro\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(([^)]*)\)/',
			// Arrow-function parameters in filters: |filter(v => v is not empty),
			// |map((value, key) => …). Twig binds these for the callback's own
			// scope, so they are the template's names, not the caller's.
			'/\(\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\)?\s*=>/',
			'/\(\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*,\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\)\s*=>/',
		];

		foreach ($patterns as $pattern) {
			if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
				continue;
			}
			foreach ($matches as $match) {
				foreach (array_slice($match, 1) as $group) {
					foreach (preg_split('/[,\s]+/', trim($group)) ?: [] as $candidate) {
						if ('' !== $candidate && 1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $candidate)) {
							$this->bound[$candidate] = true;
						}
					}
				}
			}
		}
	}
}
