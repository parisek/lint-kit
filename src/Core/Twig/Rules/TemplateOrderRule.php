<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a component template doesn't follow the canonical top-level order.
 *
 * Project convention (twig.md "Template Order"):
 *  1. metadata `{# … #}` comment
 *  2. `{% import '@macro/icons/icons.twig' as icons %}` (optional)
 *  3. `{% extends '@component/component/component.twig' %}`
 *  4. `{% set component = { … } %}`
 *  5. `{% block content %} … {% endblock %}`
 *
 * Twig parses comments out of the AST, so this rule reads the source file
 * directly (same trick as `UnguardedOutputRule`) and uses anchored regexes
 * to locate each construct's line. Pure AST line numbers are unreliable
 * because hoisted nodes (`extends`, blocks) don't always carry a
 * source-faithful line on every Twig version.
 *
 * Scope is intentionally tight: only fires on templates that extend
 * `@component/component/component.twig` (the canonical component shape).
 * Macro files, partials, and base templates without that parent are free
 * to use whatever order makes sense.
 *
 * Metadata-block presence is owned by `ComponentMetadataRule` — this rule
 * only enforces relative ordering of the four code constructs.
 */
final class TemplateOrderRule extends AbstractNodeRule
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

		$templateName = $node->getTemplateName();
		if (null === $templateName || str_contains(basename($templateName), 'styleguide')) {
			return $node;
		}

		if (!is_readable($templateName)) {
			return $node;
		}

		$contents = file_get_contents($templateName);
		if (false === $contents) {
			return $node;
		}

		$lines = explode("\n", $contents);

		$firstImport = null;
		$lastImport = null;
		$extends = null;
		$setComponent = null;
		$blockContent = null;
		$inMacro = false;

		foreach ($lines as $i => $line) {
			$lineNum = $i + 1;

			if (preg_match('/^\s*\{%-?\s*macro\s+/', $line)) {
				$inMacro = true;
				continue;
			}

			if (preg_match('/^\s*\{%-?\s*endmacro\b/', $line)) {
				$inMacro = false;
				continue;
			}

			if ($inMacro) {
				continue;
			}

			if (preg_match('/^\s*\{%-?\s*import\s+/', $line)) {
				$firstImport ??= $lineNum;
				$lastImport = $lineNum;
				continue;
			}

			if (null === $extends && preg_match('/^\s*\{%-?\s*extends\s+/', $line)) {
				$extends = $lineNum;
				continue;
			}

			if (null === $setComponent && preg_match('/^\s*\{%-?\s*set\s+component\s*=/', $line)) {
				$setComponent = $lineNum;
				continue;
			}

			if (null === $blockContent && preg_match('/^\s*\{%-?\s*block\s+content\b/', $line)) {
				$blockContent = $lineNum;
			}
		}

		if (null !== $extends && null !== $lastImport && $lastImport > $extends) {
			$this->addWarning(
				\sprintf(
					"Template order: '{%% import %%}' on line %d should come before '{%% extends %%}' on line %d",
					$lastImport,
					$extends,
				),
				$node,
				'TemplateOrder',
			);
		}

		if (null !== $extends && null !== $setComponent && $setComponent < $extends) {
			$this->addWarning(
				\sprintf(
					"Template order: '{%% set component %%}' on line %d should come after '{%% extends %%}' on line %d",
					$setComponent,
					$extends,
				),
				$node,
				'TemplateOrder',
			);
		}

		if (null !== $setComponent && null !== $blockContent && $blockContent < $setComponent) {
			$this->addWarning(
				\sprintf(
					"Template order: '{%% block content %%}' on line %d should come after '{%% set component %%}' on line %d",
					$blockContent,
					$setComponent,
				),
				$node,
				'TemplateOrder',
			);
		}

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
}
