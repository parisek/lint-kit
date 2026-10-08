<?php

declare(strict_types=1);

namespace Parisek\LintKit\Core\Twig\Rules;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use TwigCsFixer\Rules\Node\AbstractNodeRule;

/**
 * Warns when a catalogue template's metadata front-comment is not valid YAML.
 *
 * `parisek/styleguide`'s ComponentParser YAML-parses the first `{# … #}`
 * comment of every catalogue main template (`<type>/<id>/<id>.twig`); a
 * ParseException there drops the component from the catalogue (surfaced via
 * /styleguide/api/health and `styleguide lint` since parisek/styleguide#69,
 * and historically with no trace at all — two components of a downstream project were
 * silently missing for weeks). The same annotation is read by the
 * update-fields tooling. This rule catches the breakage at lint time,
 * in CI, before it ever reaches a running styleguide.
 *
 * Real-world triggers this guards against:
 *  - unquoted values containing `: ` mid-string — `description: legacy
 *    `body.home { padding-top: 0 }`` (a colon inside a plain scalar);
 *  - values STARTING with a quote — `title: "More" button label` (YAML ends
 *    the scalar at the second quote and chokes on the trailing text).
 *
 * Scope mirrors the runtime's FATAL parse surface exactly (Codex review of
 * tailwind-base#183 flagged an earlier `name:`-line gate as diverging from
 * the runtime): the rule fires on main templates only — files whose basename
 * equals their parent directory name, the `<id>/<id>.twig` shape that
 * parse()/parseAll() read — and parses their first comment UNCONDITIONALLY,
 * just like the runtime does. Everything else matches the runtime's
 * non-fatal surface and stays exempt: `styleguide.*` fixtures and variant
 * siblings (discoverVariants() falls back silently per-field — deliberate,
 * see the package's variant-annotation contract), `_`-prefixed partial
 * directories (the catalogue walk never indexes them as entries), and
 * free-standing includes/macros, including `macro/<id>/<id>.twig`.
 *
 * Parsing mechanics mirror ComponentParser::parseTwigComment() 1:1 — CR
 * normalisation, tabs converted to 4 spaces, first comment only.
 */
final class MetadataYamlParsesRule extends AbstractNodeRule
{
	public function enterNode(Node $node, Environment $env): Node
	{
		if (!$node instanceof ModuleNode) {
			return $node;
		}

		$templateName = $node->getTemplateName();
		if (null === $templateName || !is_readable($templateName)) {
			return $node;
		}

		// Main-template shape gate: <id>/<id>.twig — the only files whose
		// front comment the runtime parses fatally. `_`-prefixed dirs mirror
		// the catalogue walk's partials exemption.
		$path = str_replace('\\', '/', $templateName);
		$dir = basename(\dirname($path));
		if ($dir !== basename($path, '.twig') || str_starts_with($dir, '_')) {
			return $node;
		}

		// A macro library (`macro/<id>/<id>.twig`) has the main-template shape
		// but is no catalogue entry: the runtime registers `@macro` and never
		// parses its comments. Without this gate, removing a dead `name:` block
		// from `macro/icons/icons.twig` promoted its prose banner into the
		// metadata slot and the rule fired on it (ADR 0017).
		if ('macro' === basename(\dirname($path, 2))) {
			return $node;
		}

		// ADR 0007: a component whose metadata lives in a usable `<id>.yaml`
		// has retired its front-comment, and this rule with it — a comment
		// that is no longer YAML (free prose documenting render behaviour,
		// which is all ADR 0007 leaves in the template) cannot drop the
		// component from the catalogue. A malformed definition is NOT
		// retirement: ComponentParser falls back to the comment, so the
		// original failure mode returns and the guard must stay on.
		if (self::hasSiblingDefinition($templateName)) {
			return $node;
		}

		$contents = file_get_contents($templateName);
		if (false === $contents) {
			return $node;
		}

		$contents = str_replace("\r", "\n", $contents);
		if (!preg_match('/{#\s*(.*?)\s*#}/s', $contents, $match) || '' === $match[1]) {
			return $node;
		}

		try {
			Yaml::parse(str_replace("\t", '    ', $match[1]));
		} catch (ParseException $e) {
			$this->addWarning(
				\sprintf(
					'Metadata front-comment is not valid YAML — the styleguide will drop this template from the catalogue. %s Quote values containing `: ` or starting with a quote.',
					$e->getMessage(),
				),
				$node,
				'MetadataYamlParses',
			);
		}

		return $node;
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
	 * Deliberately duplicated in ComponentMetadataRule rather than shared —
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
}
