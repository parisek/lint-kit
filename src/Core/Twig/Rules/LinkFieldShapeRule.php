<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a `component_*({…})` call passes a plain string to a field the
 * component's own definition declares as `type: link` with `shape: link`.
 *
 * **`shape:` is the discriminator, not `type:`** — and getting that wrong makes
 * the rule worse than useless. A `type: link` field reaches the template in one
 * of two shapes, and both are legitimate:
 *
 *   shape: link   the ACF link object `{ url, title, attributes }` — the
 *                 template guards on `content.x.url`. A string breaks it.
 *   shape: url    already flattened to the href string — the template prints
 *                 `content.x` directly, as `button.twig` does. A string is
 *                 CORRECT here, and every `component_button({ url: '#' })`
 *                 call in the codebase relies on it.
 *
 * Measured across two projects while this rule was written: 49 fields are
 * `shape: link`, 36 are `shape: url`, and 16 declare no shape at all. Keying on
 * `type: link` alone would have flagged all 52 of the latter two groups —
 * more false positives than there are real candidates. Fields with no `shape:`
 * are left alone for the same reason: unknowable is not the same as wrong.
 *
 * A link object is guarded on its inner key rather than on the field itself,
 * because an empty link field still serialises as a truthy `{}`:
 *
 *     {% if module.more_link.url %}
 *
 * Hand a string to that guard and Twig resolves `.url` on a scalar to null.
 * The condition is false, the anchor is never emitted, and **nothing reports
 * it**: the template is valid, the render returns HTTP 200, no linter has an
 * opinion. The only symptom is an element that silently isn't there.
 *
 * That is the bug this rule exists for. On one downstream project the features
 * page lost its "more information" link on every category at once, because the
 * fixture passed `more_link: homeUrl ~ 'page/feature-detail'` where the
 * component expected an object. It was found by a human comparing screenshots
 * against production, months later — and the component already had a behaviour
 * spec, which passed, because it asserted the search and filter interactions
 * and never that the link rendered at all.
 *
 * Detection (AST + the component's YAML definition):
 *  - Match a `FunctionExpression` named `component_<id>`; `<id>` maps to the
 *    component directory by underscore→hyphen (`component_features_list` →
 *    `features-list`).
 *  - Read that component's `<id>.yaml` (definition-kit sidecar, ADR 0007) and
 *    collect every field declared `type: link` AND `shape: link`.
 *  - Walk the call's hash literal against that field map and warn on any
 *    link-typed key whose value is a string.
 *
 * Nested fields are walked, not just the top level — and that is load-bearing
 * rather than thoroughness for its own sake. The motivating bug sits at
 * `fields → modules (type: repeater) → fields → more_link`, so a rule
 * inspecting only the call's own top-level keys would have missed the very
 * defect it was written for. `repeater` recurses into each item of the value
 * array; `group` recurses into the value directly.
 *
 * What is deliberately NOT flagged:
 *  - An empty string (`app_store_url: ''`). It is the idiomatic "no link here"
 *    in fixtures and behaves identically to an absent field. Measured before
 *    this rule was written: of ~80 string-into-link occurrences in one project,
 *    nearly all were `''` — flagging them would have produced a wall of noise
 *    on adoption and taught everyone to reach for the allowlist.
 *  - A variable, function call, ternary or filter chain. The rule cannot know
 *    the shape of `content.link`, and guessing would trade a silent false
 *    negative for a loud false positive.
 *  - Dynamic keys, and a pre-built variable passed instead of a hash literal —
 *    there is nothing to inspect in either case.
 *
 * A concatenation (`homeUrl ~ 'page/x'`) IS flagged. It is a string like any
 * other and fails the guard identically; it merely looks more deliberate,
 * which is precisely what made the original bug survive review.
 */
final class LinkFieldShapeRule extends AbstractNodeRule
{
	/**
	 * Where a definition may live, in search order, as absolute directories.
	 * The project passes them in (the preset derives them from its template
	 * roots). They are NOT resolved from this file: once the rule lives in
	 * `vendor/`, an offset from `__DIR__` points into the package, not into the
	 * project. Resolving from the roots rather than from the linted file's path
	 * keeps the lookup independent of WHERE the call site sits: page fixtures,
	 * other components and macros all resolve the same way.
	 *
	 * The order is the contract: the component tree comes first and any
	 * fixture root after it, so a real component always wins and a fixture
	 * definition can never shadow one. The reverse order would let a test file
	 * change what the rule reports about production code.
	 *
	 * An empty list means no definition is ever found and the rule reports
	 * nothing. The preset refuses to build a rule with no roots, so that state
	 * cannot arise by omission.
	 *
	 * @var list<string>
	 */
	private readonly array $componentRoots;

	/**
	 * @param list<string> $componentRoots directories that hold `<id>/<id>.yaml` definitions
	 */
	public function __construct(array $componentRoots)
	{
		$this->componentRoots = array_map(static fn (string $root): string => rtrim($root, '/') . '/', $componentRoots);
	}

	/** @var array<string, array<string, mixed>|null> component id → field map, null when it has no definition */
	private array $definitions = [];

	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof FunctionExpression) {
			return $node;
		}

		$function = $node->getAttribute('name');
		if (!\is_string($function) || !str_starts_with($function, 'component_')) {
			return $node;
		}

		$arguments = $node->getNode('arguments');
		if (!$arguments->hasNode('0')) {
			return $node;
		}

		$hash = $arguments->getNode('0');
		if (!$hash instanceof ArrayExpression) {
			return $node; // pre-built variable — nothing to inspect
		}

		$fields = $this->fieldsFor(substr($function, \strlen('component_')));
		if (null === $fields) {
			return $node;
		}

		$this->inspect($hash, $fields);

		return $node;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function inspect(ArrayExpression $hash, array $fields): void
	{
		foreach ($hash->getKeyValuePairs() as $pair) {
			$key = $pair['key'];
			if (!$key instanceof ConstantExpression) {
				continue; // dynamic key — nothing to name in the message
			}

			$name = $key->getAttribute('value');
			if (!\is_string($name) || !isset($fields[$name]) || !\is_array($fields[$name])) {
				continue;
			}

			$spec = $fields[$name];
			$type = $spec['type'] ?? null;
			$value = $pair['value'];

			if ('link' === $type) {
				// shape: url is already an href string; no shape at all is
				// unknowable. Only shape: link promises an object.
				if ('link' === ($spec['shape'] ?? null)) {
					$this->checkLink($name, $value);
				}

				continue;
			}

			$sub = $spec['fields'] ?? null;
			if (!\is_array($sub) || !$value instanceof ArrayExpression) {
				continue;
			}

			if ('repeater' === $type) {
				// The value is a list of rows; each row is its own hash.
				foreach ($value->getKeyValuePairs() as $row) {
					if ($row['value'] instanceof ArrayExpression) {
						$this->inspect($row['value'], $sub);
					}
				}
				continue;
			}

			if ('group' === $type) {
				$this->inspect($value, $sub);
			}
		}
	}

	private function checkLink(string $name, Node $value): void
	{
		if ($value instanceof ConstantExpression) {
			$literal = $value->getAttribute('value');
			// '' is the idiomatic "no link" and behaves like an absent field.
			if (!\is_string($literal) || '' === $literal) {
				return;
			}
		} elseif (!$value instanceof ConcatBinary) {
			// Variables, calls, ternaries — shape unknowable, stay quiet.
			return;
		}

		$this->addWarning(
			"'{$name}' is declared type: link, so the component reads it as a link object and guards on "
			. "'{$name}.url' — a string fails that guard silently and the element is never rendered; "
			. "pass { url: …, title: … } instead",
			$value,
			'LinkFieldShape',
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fieldsFor(string $function): ?array
	{
		$id = str_replace('_', '-', $function);

		if (\array_key_exists($id, $this->definitions)) {
			return $this->definitions[$id];
		}

		$this->definitions[$id] = null;

		$path = null;
		foreach ($this->componentRoots as $root) {
			$candidate = $root . $id . '/' . $id . '.yaml';
			if (is_file($candidate)) {
				$path = $candidate;
				break;
			}
		}

		if ($path === null) {
			return null;
		}

		try {
			$parsed = Yaml::parseFile($path);
		} catch (\Throwable) {
			// A malformed definition is MetadataYamlParsesRule's subject,
			// not this rule's — never turn one rule's finding into another's crash.
			return null;
		}

		if (\is_array($parsed) && \is_array($parsed['fields'] ?? null)) {
			$this->definitions[$id] = $parsed['fields'];
		}

		return $this->definitions[$id];
	}
}
