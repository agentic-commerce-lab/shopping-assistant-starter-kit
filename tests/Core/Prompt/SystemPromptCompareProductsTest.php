<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The compare-products paragraph, in its own class rather than in {@see SystemPromptTest} or
 * {@see SystemPromptMatchReasonsTest}: the same reason this project already splits other
 * config-specific test classes out (mago's method cap is a fair reading of the alternative).
 *
 * Added after a live eval run on Gemini 3.7 Flash showed `compare_two_products`' "beginner" archetype
 * only rendering one of the two compared products in 2 of 3 runs (2026-08-29) — the model searched
 * each product in turn instead of calling `compare_products` with both ids, and `RULES`' own "the shop
 * shows only your most recent search" rule then dropped the first one. No grounding rule was broken
 * (no invented product, no unbacked claim) — the model simply never learned that a comparison request
 * needs the dedicated tool rather than two separate searches.
 */
final class SystemPromptCompareProductsTest extends TestCase
{
    public function testOmitsTheCompareProductsParagraphWhenTheFlagIsOff(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableCompareProducts: false));

        self::assertStringNotContainsString('compare_products', $prompt);
    }

    public function testIncludesTheCompareProductsParagraphWhenTheFlagIsOn(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableCompareProducts: true));

        self::assertStringContainsString('compare_products', $prompt);
        self::assertStringContainsString('one at a time', $prompt);
    }
}
