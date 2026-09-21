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

    public function testTheStandingRuleAgainstInventedSubstitutesSurvivesTheSettingBeingOn(): void
    {
        // The new permission is narrow — a listed sibling of the same product — and it must not read
        // as licence to reach for a different product. The older rule is what stops that, so it has
        // to still be there once the permission is granted.
        self::assertStringContainsString('do not suggest unverified substitutes', $this->prompt(suggest: true));
    }
}
