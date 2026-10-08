<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a component template's metadata block is missing or malformed.
 *
 * Project convention (twig.md "Required Metadata Block"): every template
 * extending `@component/component/component.twig` opens with a Twig comment
 * holding at minimum `name:`. The styleguide builder reads this block to
 * populate component listings, so missing/empty values silently produce
 * broken styleguide entries.
 *
 * Twig parses comments OUT of the AST, so this rule reads the source
 * file directly (same trick as `UnguardedOutputRule` and `TemplateOrderRule`)
 * and parses the first `{# … #}` block manually. The parser is intentionally
 * lenient about quoting — `name: "Foo"`, `name: 'Foo'`, and `name: Foo` all
 * resolve to `Foo`.
 *
 * Required keys (fire on missing or empty):
 *  - `name:`      non-empty string — always required
 *  - `category:`  non-empty string — required for component templates;
 *                 NOT required for page templates (`templates/page/` path).
 *                 Page templates use a different styleguide convention
 *                 (name / usage / description / weight) with no mandatory
 *                 bucket classification — see styleguide.md § Page Metadata.
 *
 * Optional keys (no requirement to include, but validated when present):
 *  - `description:` free text — optional, value may be empty `""`
 *  - `asana:`  absolute URL, http(s)://, must contain `asana.com`
 *  - `drupal:` site-relative path (`/…`, not `//`) or absolute http(s) URL
 *  - `web:`    site-relative path (`/…`, not `//`) or absolute http(s) URL
 *
 * Empty optional values for URL/path keys are flagged so authors don't
 * leave half-edited keys behind — if the key isn't applicable, omit it.
 */
final class ComponentMetadataRule extends AbstractNodeRule
{
	private const COMPONENT_PARENT = '@component/component/component.twig';

	/** Required for every template extending the component base. */
	private const REQUIRED_KEYS = ['name'];

	/** Required only for component templates, not page templates. */
	private const COMPONENT_ONLY_REQUIRED_KEYS = ['category'];

	/** Keys whose value, when present, must be non-empty. */
	private const REQUIRED_NON_EMPTY = ['name', 'category'];

	private const OPTIONAL_KEYS = ['asana', 'drupal', 'web'];

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

		// ADR 0007: once a component's metadata lives in a usable `<id>.yaml`,
		// the twig front-comment is retired for that component and this rule
		// with it — requiring keys here would flag templates the catalogue
		// reads correctly. A malformed definition is NOT retirement: the
		// runtime falls back to the comment, so the guard stays on.
		if (self::hasSiblingDefinition($templateName)) {
			return $node;
		}

		$contents = file_get_contents($templateName);
		if (false === $contents) {
			return $node;
		}

		// Page templates (those living under templates/page/) follow a different
		// styleguide convention: name / usage / description / weight — no mandatory
		// `category` bucket. Detect them by path so we can relax the category check.
		$isPageTemplate = str_contains(str_replace('\\', '/', $templateName), '/templates/page/');

		$metadata = $this->parseFirstComment($contents);
		if (null === $metadata) {
			$hint = $isPageTemplate
				? 'add {# name: … #} at the top of the template'
				: 'add {# name: …, category: … #} at the top of the template';
			$this->addWarning(
				"Component metadata block missing — {$hint}",
				$node,
				'ComponentMetadata',
			);

			return $node;
		}

		// Keys required for every template extending the component base.
		foreach (self::REQUIRED_KEYS as $key) {
			if (!\array_key_exists($key, $metadata)) {
				$this->addWarning(
					\sprintf("Component metadata: missing required key '%s'", $key),
					$node,
					'ComponentMetadata',
				);
			}
		}

		// Keys required only for component templates (not page templates).
		if (!$isPageTemplate) {
			foreach (self::COMPONENT_ONLY_REQUIRED_KEYS as $key) {
				if (!\array_key_exists($key, $metadata)) {
					$this->addWarning(
						\sprintf("Component metadata: missing required key '%s'", $key),
						$node,
						'ComponentMetadata',
					);
				}
			}
		}

		foreach (self::REQUIRED_NON_EMPTY as $key) {
			if (\array_key_exists($key, $metadata) && '' === trim($metadata[$key])) {
				$this->addWarning(
					\sprintf("Component metadata: '%s' has empty value", $key),
					$node,
					'ComponentMetadata',
				);
			}
		}

		foreach (self::OPTIONAL_KEYS as $key) {
			if (!\array_key_exists($key, $metadata)) {
				continue;
			}
			$this->validateOptional($node, $key, trim($metadata[$key]));
		}

		return $node;
	}

	private function validateOptional(Node $node, string $key, string $value): void
	{
		if ('' === $value) {
			$this->addWarning(
				\sprintf("Component metadata: '%s' empty value (omit the key entirely if not applicable)", $key),
				$node,
				'ComponentMetadata',
			);

			return;
		}

		if ('asana' === $key) {
			if (!preg_match('#^https?://#i', $value)) {
				$this->addWarning(
					"Component metadata: 'asana' should be an absolute URL starting with http(s)://",
					$node,
					'ComponentMetadata',
				);

				return;
			}
			if (false === stripos($value, 'asana.com')) {
				$this->addWarning(
					"Component metadata: 'asana' URL should point to asana.com domain",
					$node,
					'ComponentMetadata',
				);
			}

			return;
		}

		// `drupal` and `web` — a site-relative path, or an absolute http(s) URL
		// when the page lives on another host. Protocol-relative `//host` stays
		// out: it inherits whatever scheme the styleguide happens to run on.
		$site_relative = '/' === $value[0] && !(isset($value[1]) && '/' === $value[1]);
		// filter_var rejects trailing text and a malformed port or host that a
		// prefix match would let through; the scheme check keeps it to http(s).
		$absolute = 1 === preg_match('#^https?://#i', $value) && false !== filter_var($value, FILTER_VALIDATE_URL);
		if (!$site_relative && !$absolute) {
			$this->addWarning(
				\sprintf("Component metadata: '%s' should be a site-relative path starting with '/' or an absolute http(s) URL", $key),
				$node,
				'ComponentMetadata',
			);
		}
	}

	/**
	 * True when the template has a sibling `<id>.yaml` that actually supplies
	 * its metadata (ADR 0007).
	 *
	 * Mirrors ComponentParser::readComponentMetadata() exactly, and the
	 * *exactness* is the point: the runtime prefers the sibling definition, but
	 * falls back to the twig front-comment when the file fails to parse or
	 * parses to something other than a map. Gating on mere file existence would
	 * therefore switch this rule off in precisely the case where the comment
	 * becomes load-bearing again — a malformed definition would silently take
	 * the front-comment's own guard down with it.
	 *
	 * Deliberately duplicated in MetadataYamlParsesRule rather than shared —
	 * config.php registers rules by explicit require_once, so a shared trait
	 * would add a registration surface for a few lines of logic.
	 */
	private static function hasSiblingDefinition(string $templateName): bool
	{
		$path = str_replace('\\', '/', $templateName);
		$yamlFile = \dirname($path) . '/' . basename($path, '.twig') . '.yaml';

		if (!is_file($yamlFile)) {
			return false;
		}

		try {
			return \is_array(Yaml::parseFile($yamlFile));
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Parse the first `{# … #}` Twig comment found in the source. Returns the
	 * key/value map, or null if the file doesn't open with a comment block.
	 *
	 * Recognized line formats inside the comment:
	 *   key: "value"
	 *   key: 'value'
	 *   key: value
	 *
	 * Lines that don't match a `key: value` shape (blank lines, prose) are
	 * ignored — the metadata block tolerates surrounding text.
	 *
	 * @return array<string, string>|null
	 */
	private function parseFirstComment(string $contents): ?array
	{
		// Skip leading whitespace and find the first non-whitespace token.
		if (!preg_match('/\S/', $contents, $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}

		$firstNonWsOffset = $m[0][1];
		if ('{#' !== substr($contents, $firstNonWsOffset, 2)) {
			return null;
		}

		$bodyStart = $firstNonWsOffset + 2;
		$endOffset = strpos($contents, '#}', $bodyStart);
		if (false === $endOffset) {
			return null;
		}

		$body = substr($contents, $bodyStart, $endOffset - $bodyStart);
		$result = [];
		foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
			// Only parse TOP-LEVEL keys (no leading indentation). Nested keys
			// inside a `fields:` block (`\tcategory:`, `\t\ttitle:`) document the
			// component's data shape — they must not shadow the top-level
			// `name:` / `description:` / `category:` metadata. Anchoring the key
			// at column 0 keeps the flat parser nesting-aware.
			if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*?)\s*$/', $line, $matches)) {
				continue;
			}
			$key = $matches[1];
			$value = $matches[2];

			if ('' !== $value) {
				$first = $value[0];
				$last = $value[\strlen($value) - 1];
				if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
					$value = substr($value, 1, -1);
				}
			}

			$result[$key] = $value;
		}

		return $result;
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
