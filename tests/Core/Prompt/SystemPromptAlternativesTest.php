<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\CapabilityRules;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The rule that lets the model read an `alternatives` list, and the reason it cannot be
 * unconditional: with the setting off no tool result ever carries the key, so an instruction about
 * it would be dead weight at best and an invitation to invent one at worst — the argument
 * {@see CapabilityRules::MATCH_REASONS_AVAILABLE} already makes for itself.
 */
final class SystemPromptAlternativesTest extends TestCase
{
    private function prompt(bool $suggest): string
    {
        return SystemPrompt::build(new AssistantConfig(suggestAlternatives: $suggest));
    }

    public function testWithTheSettingOnTheModelIsToldWhatAnAlternativeIs(): void
    {
        self::assertStringContainsString(CapabilityRules::ALTERNATIVES_AVAILABLE, $this->prompt(suggest: true));
    }

    public function testWithTheSettingOffNothingIsSaidAboutAlternativesAtAll(): void
    {
        self::assertStringNotContainsString(CapabilityRules::ALTERNATIVES_AVAILABLE, $this->prompt(suggest: false));
    }

    public function testAnOwnSizeOutranksADifferentProduct(): void
    {
        // The pilot's own example: asked for trousers in 32x32 and sold out, a shopper wants to hear
        // about 31x32 — not about which other trousers exist.
        self::assertStringContainsString(
            'Do not offer a different product instead',
            CapabilityRules::ALTERNATIVES_AVAILABLE,
        );
    }

    public function testWithNoOwnSizeLeftADifferentProductIsStillAllowed(): void
    {
        // The other half of the same decision, and the reason this is a ranking rather than a ban.
        // No size left, no size asked for, or no variants at all — then a different product is a
        // useful answer and always was.
        self::assertStringContainsString('you may offer other products', CapabilityRules::ALTERNATIVES_AVAILABLE);
    }

    public function testTheStandingRuleAgainstInventedSubstitutesSurvivesTheSettingBeingOn(): void
    {
        // The new permission is narrow — a listed sibling of the same product — and it must not read
        // as licence to reach for a different product. The older rule is what stops that, so it has
        // to still be there once the permission is granted.
        self::assertStringContainsString('do not suggest unverified substitutes', $this->prompt(suggest: true));
    }
}
