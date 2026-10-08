<?php

declare(strict_types=1);

namespace Parisek\LintKit\Tests\Unit;

use Parisek\LintKit\Core\Twig\Rules\EmptyAltRule;
use Parisek\LintKit\Twig\Preset;
use Parisek\LintKit\WordPress\Twig\Rules\TranslationThemeNameRule;
use PHPUnit\Framework\TestCase;
use TwigCsFixer\Rules\Node\NodeRuleInterface;
use TwigCsFixer\Rules\RuleInterface;

final class PresetTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../Fixtures/Twig';
    private const COMPONENTS = __DIR__ . '/../Fixtures/Twig/definitions';

    /**
     * @param array<string, mixed> $options
     *
     * @return list<class-string>
     */
    private function ruleClasses(array $options): array
    {
        $config = Preset::config($options + ['templates' => [self::TEMPLATES], 'componentRoots' => [self::COMPONENTS]]);

        return array_map(
            static fn (RuleInterface|NodeRuleInterface $rule): string => $rule::class,
            $config->getRuleset()->getRules()
        );
    }

    /**
     * The old config.php needed one `require_once` and one `addRule()` for each rule, and a rule with
     * only one of the two silently never ran. Autoload removes the first. This test guards the second:
     * every rule class in the Core folder must be registered.
     */
    public function testEveryCoreRuleClassIsRegistered(): void
    {
        $registered = $this->ruleClasses([]);

        $missing = [];
        foreach (glob(__DIR__ . '/../../src/Core/Twig/Rules/*Rule.php') ?: [] as $file) {
            $class = 'Parisek\\LintKit\\Core\\Twig\\Rules\\' . basename($file, '.php');
            if (!\in_array($class, $registered, true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'These Core rules exist but the preset does not register them.');
        $this->assertContains(EmptyAltRule::class, $registered);
    }

    public function testTheWordPressRuleLoadsOnlyWithItsSet(): void
    {
        $this->assertNotContains(TranslationThemeNameRule::class, $this->ruleClasses([]));
        $this->assertContains(
            TranslationThemeNameRule::class,
            $this->ruleClasses(['sets' => ['core', 'wordpress'], 'themeName' => 'my-theme'])
        );
    }

    public function testRemoveRulesDropsARule(): void
    {
        $this->assertNotContains(EmptyAltRule::class, $this->ruleClasses(['removeRules' => [EmptyAltRule::class]]));
    }

    public function testTemplatesAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"templates" is required');

        Preset::config([]);
    }

    public function testATemplateDirectoryMustExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');

        Preset::config(['templates' => [self::TEMPLATES . '/does-not-exist']]);
    }

    public function testAnUnknownSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rule set "drupal"');

        Preset::config(['templates' => [self::TEMPLATES], 'componentRoots' => [self::COMPONENTS], 'sets' => ['core', 'drupal']]);
    }

    public function testTheWordPressSetNeedsAThemeName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"themeName" is required');

        Preset::config(['templates' => [self::TEMPLATES], 'componentRoots' => [self::COMPONENTS], 'sets' => ['core', 'wordpress']]);
    }

    /**
     * Without component definitions LinkFieldShapeRule finds nothing and reports nothing. That must not
     * happen by omission.
     */
    public function testMissingComponentRootsFailLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No component definitions can be found');

        Preset::config(['templates' => [__DIR__]]);
    }
}
