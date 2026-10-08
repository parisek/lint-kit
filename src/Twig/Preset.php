<?php

declare(strict_types=1);

namespace Parisek\LintKit\Twig;

use Parisek\LintKit\Core\Twig\Rules as Core;
use Parisek\LintKit\WordPress\Twig\Rules as WordPress;
use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Rules\Function\IncludeFunctionRule;
use TwigCsFixer\Rules\Node\NodeRuleInterface;
use TwigCsFixer\Rules\Punctuation\PunctuationSpacingRule;
use TwigCsFixer\Rules\RuleInterface;
use TwigCsFixer\Rules\Whitespace\IndentRule;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

/**
 * Builds the `twig-cs-fixer` config of a project.
 *
 * A project config is a few lines:
 *
 *     return \Parisek\LintKit\Twig\Preset::config([
 *         'templates' => [__DIR__ . '/../../templates'],
 *         'sets' => ['core', 'wordpress'],
 *         'themeName' => 'my-theme',
 *     ]);
 *
 * The preset replaces the hand-kept `require_once` and `addRule()` lists of the
 * old `config.php`. It reads no file of the project. Every input arrives as an
 * option (requirement R13.16).
 *
 * Options:
 *
 *  - `templates`      list<string>  Directories to lint. Required. Each must exist, so a typo fails loudly.
 *  - `sets`           list<string>  `core` (default) and `wordpress`. A set of another CMS is never loaded.
 *  - `componentRoots` list<string>  Directories that hold `<id>/<id>.yaml` definitions, searched in order.
 *                                   Default: `<templates>/component` of each template directory that has one.
 *  - `extraRoots`     list<string>  Template roots that never need an `{% if %}` guard, on top of the built-in ones.
 *                                   The `wordpress` set adds `site`.
 *  - `themeName`      string        The translation text domain. Required with the `wordpress` set.
 *  - `style`          bool          The house style: indent of 2 with tabs, spacing around braces, no `include()` rule.
 *                                   Default: true. A project that wants its own style passes false.
 *  - `rules`          list<RuleInterface|NodeRuleInterface>  Project rules, added after the set rules.
 *  - `removeRules`    list<class-string>  Rules to remove (the old "Layer C"). Applied last, so it can remove any rule.
 */
final class Preset
{
    private const SETS = ['core', 'wordpress'];

    /**
     * @param array{
     *     templates?: list<string>,
     *     sets?: list<string>,
     *     componentRoots?: list<string>,
     *     extraRoots?: list<string>,
     *     themeName?: string,
     *     style?: bool,
     *     rules?: list<RuleInterface|NodeRuleInterface>,
     *     removeRules?: list<class-string>,
     * } $options
     */
    public static function config(array $options): Config
    {
        $templates = $options['templates'] ?? [];
        if ([] === $templates) {
            throw new \InvalidArgumentException('Preset option "templates" is required: pass the directories to lint.');
        }
        foreach ($templates as $directory) {
            if (!is_dir($directory)) {
                throw new \InvalidArgumentException(\sprintf('Preset option "templates": "%s" is not a directory.', $directory));
            }
        }

        $sets = $options['sets'] ?? ['core'];
        foreach ($sets as $set) {
            if (!\in_array($set, self::SETS, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown rule set "%s". Known sets: %s.', $set, implode(', ', self::SETS)));
            }
        }
        if (!\in_array('core', $sets, true)) {
            throw new \InvalidArgumentException('The "core" set is required: the other sets build on it.');
        }

        $extraRoots = $options['extraRoots'] ?? [];
        $themeName = $options['themeName'] ?? null;
        if (\in_array('wordpress', $sets, true)) {
            if (!\is_string($themeName) || '' === trim($themeName)) {
                throw new \InvalidArgumentException('Preset option "themeName" is required with the "wordpress" set (the translation text domain).');
            }
            $extraRoots[] = 'site';
        }

        $componentRoots = $options['componentRoots'] ?? self::defaultComponentRoots($templates);
        if ([] === $componentRoots) {
            throw new \InvalidArgumentException(
                'No component definitions can be found. Pass "componentRoots", or give "templates" a directory with a "component" folder.'
            );
        }

        $ruleset = new Ruleset();
        $ruleset->addStandard(new TwigCsFixer());

        if ($options['style'] ?? true) {
            $ruleset->overrideRule(new IndentRule(2, true));
            $ruleset->overrideRule(new PunctuationSpacingRule(['}' => 1], ['{' => 1]));
            $ruleset->removeRule(IncludeFunctionRule::class);
        }

        foreach (self::coreRules(array_values(array_unique($extraRoots)), $componentRoots) as $rule) {
            $ruleset->addRule($rule);
        }
        if (\in_array('wordpress', $sets, true)) {
            $ruleset->addRule(new WordPress\TranslationThemeNameRule(trim((string) $themeName)));
        }
        foreach ($options['rules'] ?? [] as $rule) {
            $ruleset->addRule($rule);
        }
        foreach ($options['removeRules'] ?? [] as $class) {
            $ruleset->removeRule($class);
        }

        $finder = new Finder();
        foreach ($templates as $directory) {
            $finder->in($directory);
        }

        $config = new Config();
        $config->setFinder($finder);
        $config->setRuleset($ruleset);
        $config->allowNonFixableRules();

        return $config;
    }

    /**
     * @param list<string> $templates
     *
     * @return list<string>
     */
    private static function defaultComponentRoots(array $templates): array
    {
        $roots = [];
        foreach ($templates as $directory) {
            $candidate = rtrim($directory, '/') . '/component';
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }

        return $roots;
    }

    /**
     * @param list<string> $extraRoots
     * @param list<string> $componentRoots
     *
     * @return list<RuleInterface|NodeRuleInterface>
     */
    private static function coreRules(array $extraRoots, array $componentRoots): array
    {
        return [
            new Core\UnguardedOutputRule($extraRoots),
            new Core\ResizerCropRule(),
            new Core\ResizerSingleFallbackRule(),
            new Core\PlainImgRule(),
            new Core\PictureImagePropRule(),
            new Core\PictureResizerRule(),
            new Core\MetadataYamlParsesRule(),
            new Core\NoopenerRule(),
            new Core\TypographyFilterRule(),
            new Core\HardcodedImagePathRule(),
            new Core\HomeUrlLinkRule(),
            new Core\EmptyAltRule(),
            new Core\ScaleValueRule(),
            new Core\WrapperClassesRule(),
            new Core\SpaceXYRule(),
            new Core\ButtonTypeRule(),
            new Core\DumpRule(),
            new Core\TemplateOrderRule(),
            new Core\ComponentButtonTypeRule(),
            new Core\ComponentContentScopeRule(),
            new Core\ComponentMetadataRule(),
            new Core\ProseOnRichTextRule(),
            new Core\StretchedLinkRelativeRule(),
            new Core\HeadingArbitraryFontSizeRule(),
            new Core\LongClassListRule(),
            new Core\CreateAttributeClassArrayRule(),
            new Core\CreateAttributeClassLengthRule(),
            new Core\CreateAttributeClassMultilineRule(),
            new Core\TranslationTypographyFilterRule(),
            new Core\TranslationPluralMissingFormatRule(),
            new Core\TranslationMissingTypographyRule(),
            new Core\InlineConditionalClassRule(),
            new Core\JsonEncodeHtmlAttrRule(),
            new Core\HardcodedI18nAttributeRule(),
            new Core\HardcodedI18nArrayKeyRule(),
            new Core\TransitionAllRule(),
            new Core\ArbitraryPxRule(),
            new Core\UniqueIdRequiredRule(),
            new Core\LinkFieldShapeRule($componentRoots),
        ];
    }
}
