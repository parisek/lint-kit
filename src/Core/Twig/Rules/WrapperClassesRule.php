<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a component template doesn't expose `content.wrapper_classes`.
 *
 * Project convention: every component that extends `@component/component/component.twig`
 * should let the parent CMS append extra Tailwind classes by including
 * `content.wrapper_classes` somewhere in its `classes:` array. Without it the
 * component's wrapper is effectively closed to per-instance styling tweaks.
 *
 * Replaces the post-scan `wrapper_classes` Python check in `format-twig.py`.
 * Detection runs AST-aware in two passes inside the same Module entry:
 *  - Inspect the Module's `parent` node — must be a `ConstantExpression` with
 *    value `@component/component/component.twig` for the rule to apply.
 *  - Walk every `GetAttrExpression` looking for `content.wrapper_classes`
 *    (covers raw `{{ content.wrapper_classes }}`, array entries
 *    `content.wrapper_classes`, ternaries — anywhere a Twig expression reads it).
 *
 * Scope widened slightly vs the Python check: the Python regex was scoped to
 * `classes:` arrays. In practice nobody references `wrapper_classes` outside
 * `classes:`, so the widened check produces the same findings on real templates
 * while being immune to multi-line bracket-tracking edge cases.
 */
final class WrapperClassesRule extends AbstractNodeRule
{
	private const COMPONENT_PARENT = '@component/component/component.twig';

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ModuleNode) {
			return $node;
		}

		if (!$this->extendsComponentBase($node)) {
			return $node;
		}

		if ($this->referencesWrapperClasses($node)) {
			return $node;
		}

		$this->addWarning(
			'Component classes should include content.wrapper_classes',
			$node,
			'WrapperClasses',
		);

		return $node;
	}

	private function extendsComponentBase(ModuleNode $module): bool
	{
		if (!$module->hasNode('parent')) {
			return false;
		}

		$parent = $module->getNode('parent');
		if (!$parent instanceof ConstantExpression) {
			return false;
		}

		return self::COMPONENT_PARENT === $parent->getAttribute('value');
	}

	private function referencesWrapperClasses(Node $node): bool
	{
		if ($node instanceof GetAttrExpression && 'method' !== $node->getAttribute('type')) {
			$attr = $node->getNode('attribute');
			if ($attr instanceof ConstantExpression
				&& 'wrapper_classes' === $attr->getAttribute('value')
				&& $this->isContentRoot($node->getNode('node'))
			) {
				return true;
			}
		}

		foreach ($node as $child) {
			if ($child instanceof Node && $this->referencesWrapperClasses($child)) {
				return true;
			}
		}

		return false;
	}

	private function isContentRoot(Node $node): bool
	{
		if (!$node instanceof NameExpression && !$node instanceof ContextVariable) {
			return false;
		}

		return 'content' === $node->getAttribute('name');
	}
}
