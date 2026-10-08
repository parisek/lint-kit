<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when `{{ X.Y }}` output is rendered without a surrounding
 * `{% if %}` or `{% for _ in X.Y %}` guard.
 *
 * Prevents empty wrapper elements in the DOM when an optional field is
 * missing. Node-based port of the regex-based `unguarded-output` lint
 * that previously lived in the `format-twig` skill.
 *
 * Guard semantics — an output is considered safe when ANY of these is true:
 *  1. There is an enclosing `{% for _ in X.Y %}` (prefix-matching).
 *  2. There is an enclosing `{% if X.Y %}` (or `{% if X.Y.sub %}`) that
 *     path-matches the printed expression (prefix-matching, as before).
 *  3. There is an enclosing `{% if %}` whose condition references a bare
 *     local variable (not a dotted path, not a Twig builtin) — the derived
 *     guard pattern:
 *       `{% set has_link = content.url is not empty %}`
 *       `{% if has_link %}<a href="{{ content.url }}">…</a>{% endif %}`
 *     `has_link` is not a dotted path, so it never lands in the guard stack;
 *     but a bare-name condition signals the author computed an existence
 *     check before rendering. Flagging the print would be a false positive.
 *
 * What does NOT count as a guard (deliberately narrow, so the rule keeps
 * catching genuinely-unguarded output):
 *  - An enclosing `{% if %}` testing an UNRELATED dotted path. Inside
 *    `{% if content.image %}{{ content.url }}{% endif %}`, `content.url` is
 *    still unguarded — `content.image` being truthy says nothing about
 *    `content.url`. Only path-matching (rule 2) can clear a dotted guard.
 *  - An `{% else %}` branch. The branch runs precisely when the if's
 *    condition is falsy, so the condition can never guard a print there.
 */
final class UnguardedOutputRule extends AbstractNodeRule
{
	/**
	 * Roots that never need an `{% if %}` guard.
	 *
	 *  - `loop`, `_self`, `_context`, `_charset` — always defined by Twig itself.
	 *  - `component` — local hash always set at the top of every template that
	 *    extends `@component/component/component.twig` via `{% set component = {…} %}`.
	 *    Its keys (`name`, `id`, `classes`, `container`, `tag`, `heading`, …) are
	 *    author-controlled, not optional CMS fields, so unguarded prints are fine.
	 *
	 * A CMS adds its own always-present roots through the `$extraRoots` constructor
	 * argument. The WordPress set passes `site`: Timber's global `TimberSite`
	 * object is present on every WordPress request (`site.charset`, `site.title`,
	 * `site.language_attributes` in the layout `<head>`). Its members must render
	 * unconditionally — `<html>` and `<meta charset>` can't be `{% if %}`-gated —
	 * so a guard suggestion there is always a false positive. `site` is not a
	 * builtin root here, because in a project without Timber it is an ordinary
	 * variable that may be absent.
	 */
	private const BUILTIN_ROOTS = ['loop', '_self', '_context', '_charset', 'component'];

	/**
	 * @param list<string> $extraRoots roots that never need a guard, on top of {@see self::BUILTIN_ROOTS}
	 */
	public function __construct(private readonly array $extraRoots = [])
	{
	}

	private function isBuiltinRoot(string $name): bool
	{
		return \in_array($name, self::BUILTIN_ROOTS, true) || \in_array($name, $this->extraRoots, true);
	}

	/**
	 * HTML5 void elements — never wrap meaningful content, so suggesting them
	 * as a guard target is always wrong. Source: https://html.spec.whatwg.org/#void-elements.
	 */
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
		'input', 'link', 'meta', 'source', 'track', 'wbr',
	];

	/**
	 * Source lines of the template currently being walked (1-indexed via $sourceLines[$i - 1]).
	 * Cached at module entry so {@see suggestWrapLine()} can scan backwards from each
	 * unguarded print without re-reading the file.
	 *
	 * @var list<string>
	 */
	private array $sourceLines = [];

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ModuleNode) {
			return $node;
		}

		$templateName = $node->getTemplateName();
		if (null !== $templateName && str_contains(basename($templateName), 'styleguide')) {
			return $node;
		}

		$this->sourceLines = [];
		if (null !== $templateName && is_readable($templateName)) {
			$contents = file_get_contents($templateName);
			if (false !== $contents) {
				$this->sourceLines = explode("\n", $contents);
			}
		}

		$this->walkNode($node, [], false);

		$this->sourceLines = [];

		return $node;
	}

	/**
	 * Walks the AST ourselves (rather than relying on the default visitor)
	 * so we can maintain a per-branch guard stack for `{% if %}/{% elseif %}/{% else %}`.
	 *
	 * @param list<string> $guardStack
	 * @param bool         $insideDerivedGuard True when this node is nested inside an
	 *                                         `{% if %}` whose condition tests a bare local
	 *                                         variable (the derived-guard pattern). NOT set by
	 *                                         dotted-path conditions or `{% else %}` branches.
	 *                                         See the class docblock for the rationale.
	 */
	private function walkNode(Node $node, array $guardStack, bool $insideDerivedGuard): void
	{
		if ($node instanceof PrintNode) {
			$this->checkPrintNode($node, $guardStack, $insideDerivedGuard);

			return;
		}

		if ($node instanceof IfNode) {
			$tests = $node->getNode('tests');
			$count = \count($tests);
			for ($i = 0; $i < $count; $i += 2) {
				$cond = $tests->getNode((string) $i);
				$branchGuards = array_merge($guardStack, $this->extractPaths($cond));
				// A bare-name condition (`{% if has_link %}`) is a derived guard;
				// a dotted-path condition (`{% if content.image %}`) is not — it
				// only guards the matching path via isGuarded(), so it can't
				// silence an unrelated print inside the branch.
				$branchDerived = $insideDerivedGuard || $this->conditionHasLocalGuard($cond);
				if ($tests->hasNode((string) ($i + 1))) {
					$this->walkNode($tests->getNode((string) ($i + 1)), $branchGuards, $branchDerived);
				}
			}
			if ($node->hasNode('else')) {
				// `{% else %}` runs only when every condition above was falsy, so the
				// if's own conditions guard nothing here. Inherit only the OUTER
				// derived-guard state — never treat the else branch itself as guarded.
				$this->walkNode($node->getNode('else'), $guardStack, $insideDerivedGuard);
			}

			return;
		}

		if ($node instanceof ForNode) {
			$seq = $node->getNode('seq');
			$bodyGuards = array_merge($guardStack, $this->extractPaths($seq));
			$this->walkNode($node->getNode('body'), $bodyGuards, $insideDerivedGuard);
			if ($node->hasNode('else')) {
				// `{% else %}` inside a `{% for %}` runs only when the sequence is empty — no new guards.
				$this->walkNode($node->getNode('else'), $guardStack, $insideDerivedGuard);
			}

			return;
		}

		foreach ($node as $child) {
			if ($child instanceof Node) {
				$this->walkNode($child, $guardStack, $insideDerivedGuard);
			}
		}
	}

	/**
	 * @param list<string> $guardStack
	 * @param bool         $insideDerivedGuard See walkNode() docblock.
	 */
	private function checkPrintNode(PrintNode $node, array $guardStack, bool $insideDerivedGuard): void
	{
		$target = $node->getNode('expr');

		while ($target instanceof FilterExpression) {
			if ($this->isJsEscapeFilter($target)) {
				return;
			}
			$target = $target->getNode('node');
		}

		if (!$target instanceof GetAttrExpression) {
			return;
		}

		$path = $this->extractPath($target);
		if (null === $path) {
			return;
		}

		$parts = explode('.', $path);
		if (\count($parts) < 2) {
			return;
		}

		if ($this->isBuiltinRoot($parts[0])) {
			return;
		}

		if ($this->isGuarded($path, $guardStack)) {
			return;
		}

		// Derived-guard safety net: an enclosing `{% if %}` testing a bare local
		// variable (e.g. `{% set has_link = content.url is not empty %}{% if has_link %}`)
		// signals the author verified existence before rendering. Unrelated
		// dotted-path conditions and `{% else %}` branches do NOT set this — see walkNode().
		if ($insideDerivedGuard) {
			return;
		}

		$message = \sprintf('{{ %s }} has no parent {%% if %%} guard', $path);

		$suggestion = $this->suggestWrapLine($node->getTemplateLine());
		if (null !== $suggestion) {
			[$lineNum, $tag] = $suggestion;
			$message .= \sprintf(
				' — wrap line %d (%s) in {%% if %s %%}',
				$lineNum,
				$tag,
				$path,
			);
		}

		$this->addWarning($message, $node, 'UnguardedOutput');
	}

	/**
	 * Scan source lines backwards from the print's location to find the nearest
	 * opening HTML tag that could plausibly be the parent fragment to wrap.
	 *
	 * Heuristic — start at the print line itself (covers `<a>{{ x }}</a>` one-liners)
	 * and walk up. Match opening tags at line start (after indent), skip void
	 * elements (no content slot) and lines that close the same tag (sibling, not parent).
	 *
	 * Returns `[line_number, '<tag>']` or null when no plausible parent is found
	 * (e.g. print is at the top of the template).
	 *
	 * @return array{int, string}|null
	 */
	private function suggestWrapLine(int $printLine): ?array
	{
		if ([] === $this->sourceLines) {
			return null;
		}

		for ($i = $printLine; $i >= 1; $i--) {
			$line = $this->sourceLines[$i - 1] ?? null;
			if (null === $line) {
				continue;
			}

			if (!preg_match('/^\s*<([a-z]+(?:[1-6])?)\b/i', $line, $m)) {
				continue;
			}

			$tagName = strtolower($m[1]);

			if (\in_array($tagName, self::VOID_ELEMENTS, true)) {
				continue;
			}

			// Lines above the print: same-line close means it's a sibling, not a parent.
			// On the print line itself, a same-line close still wraps the print.
			if ($i < $printLine && preg_match('/<\/' . preg_quote($tagName, '/') . '>/i', $line)) {
				continue;
			}

			return [$i, '<' . $tagName . '>'];
		}

		return null;
	}

	private function isJsEscapeFilter(FilterExpression $filter): bool
	{
		$name = $filter->getAttribute('name');
		if ('e' !== $name && 'escape' !== $name) {
			return false;
		}

		$arguments = $filter->getNode('arguments');
		$strategy = null;
		if ($arguments->hasNode('0')) {
			$strategy = $arguments->getNode('0');
		} elseif ($arguments->hasNode('strategy')) {
			$strategy = $arguments->getNode('strategy');
		}

		return $strategy instanceof ConstantExpression && 'js' === $strategy->getAttribute('value');
	}

	/**
	 * True when the condition references a bare local variable — a NameExpression
	 * that is NOT the base of a dotted access and NOT a Twig builtin. This is the
	 * signature of a derived guard (`{% set has_link = … %}{% if has_link %}`),
	 * which the rule trusts as an intent signal (guard semantics, rule 3).
	 *
	 * Conditions that test only dotted paths (`{% if content.image %}`,
	 * `{% if content.url is not empty %}`) return false — they guard via
	 * path-matching in {@see isGuarded()}, not via this broad signal, so they
	 * can never silence a print of an unrelated path.
	 */
	private function conditionHasLocalGuard(Node $node): bool
	{
		if ($node instanceof GetAttrExpression) {
			// Descend into the attribute + call arguments, but NOT the base `node`:
			// the base of `content.image` is an object root, not a standalone
			// derived boolean. Skipping it is what keeps dotted conditions from
			// counting as a guard here.
			foreach (['attribute', 'arguments'] as $childName) {
				if ($node->hasNode($childName)
					&& $this->conditionHasLocalGuard($node->getNode($childName))
				) {
					return true;
				}
			}

			return false;
		}

		if ($node instanceof NameExpression || $node instanceof ContextVariable) {
			$name = $node->getAttribute('name');

			return \is_string($name) && !$this->isBuiltinRoot($name);
		}

		foreach ($node as $child) {
			if ($child instanceof Node && $this->conditionHasLocalGuard($child)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the dotted path for a standalone variable access — `content.button.url`.
	 * Returns null for method calls (`icons.get(...)`), non-string attribute keys, or
	 * anything that isn't a pure chain of `GetAttrExpression` rooted at a name.
	 */
	private function extractPath(Node $node): ?string
	{
		if ($node instanceof NameExpression || $node instanceof ContextVariable) {
			$name = $node->getAttribute('name');

			return \is_string($name) ? $name : null;
		}

		if (!$node instanceof GetAttrExpression) {
			return null;
		}

		// Skip method calls — their dynamic args can't be guarded meaningfully.
		// Plain property access has `type === 'any'`, bracket access `type === 'array'`.
		if ('method' === $node->getAttribute('type')) {
			return null;
		}

		$parentPath = $this->extractPath($node->getNode('node'));
		if (null === $parentPath) {
			return null;
		}

		$attr = $node->getNode('attribute');
		if (!$attr instanceof ConstantExpression) {
			return null;
		}

		$attrValue = $attr->getAttribute('value');
		if (!\is_string($attrValue)) {
			return null;
		}

		return $parentPath . '.' . $attrValue;
	}

	/**
	 * Collect every dotted path referenced inside an arbitrary expression tree.
	 * Used on `{% if <expr> %}` and `{% for _ in <expr> %}` to seed the guard stack.
	 *
	 * @return list<string>
	 */
	private function extractPaths(Node $node): array
	{
		$paths = [];
		$this->collectPaths($node, $paths);

		return array_values(array_unique($paths));
	}

	/**
	 * @param list<string> $paths
	 */
	private function collectPaths(Node $node, array &$paths): void
	{
		if ($node instanceof GetAttrExpression && 'method' !== $node->getAttribute('type')) {
			$path = $this->extractPath($node);
			if (null !== $path && str_contains($path, '.')) {
				$paths[] = $path;

				// Don't descend — the inner `content.button` of `content.button.url`
				// is already covered by prefix matching in isGuarded().
				return;
			}
		}

		foreach ($node as $child) {
			if ($child instanceof Node) {
				$this->collectPaths($child, $paths);
			}
		}
	}

	/**
	 * A path `content.button.url` is guarded if the stack contains either:
	 *  - the path itself (`content.button.url`), or
	 *  - any dotted prefix of length >= 2 (`content.button`).
	 *
	 * Stopping at length 2 mirrors the Python implementation: a bare
	 * `content` check is too weak to signal intent to render `content.button`.
	 *
	 * @param list<string> $guardStack
	 */
	private function isGuarded(string $path, array $guardStack): bool
	{
		$parts = explode('.', $path);
		for ($j = 2; $j <= \count($parts); $j++) {
			$prefix = implode('.', \array_slice($parts, 0, $j));
			if (\in_array($prefix, $guardStack, true)) {
				return true;
			}
		}

		return false;
	}
}
