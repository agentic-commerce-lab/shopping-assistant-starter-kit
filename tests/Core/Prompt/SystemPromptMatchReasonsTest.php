<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The match-reasons paragraph, in its own class rather than in {@see SystemPromptTest}: the same
 * reason this project already splits other config-specific test classes out (mago's method cap is a
 * fair reading of the alternative).
 *
 * Mirrors {@see SystemPromptTest::testThePromptStopsOrderingEscalationWhenTheToolIsGone()}'s own
 * mechanism: the paragraph is appended only when the capability it describes actually exists,
 * because an unconditional instruction to narrate reason codes the model will usually never receive
 * (enableMatchReasons defaults to false) is dead weight at best.
 */
final class SystemPromptMatchReasonsTest extends TestCase
{
    public function testOmitsTheMatchReasonsParagraphWhenTheFlagIsOff(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableMatchReasons: false));

        self::assertStringNotContainsString('reason codes', $prompt);
    }

    public function testIncludesTheMatchReasonsParagraphWhenTheFlagIsOn(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableMatchReasons: true));

        self::assertStringContainsString('reason codes', $prompt);
        self::assertStringContainsString('Never state a reason that was not given to you.', $prompt);
    }

    /**
     * Reported from staging, 2026-09-03: *"The search found no helmets with a cat print, though it
     * did return Chain Wear Indicator, which matched 'cat print' but is not a helmet."*
     *
     * The paragraph used to permit every code equally — *"You may mention these plainly in your own
     * words"* — and `matched_term:*` is not a fact about the product. It says which of the model's
     * own search terms found the card, which is routing information for attributing products to the
     * right half of a two-term answer. Said out loud it is the assistant explaining its search box,
     * and on a bad hit it is the assistant explaining a product the shopper should never have seen.
     */
    public function testTheTermCodeIsRoutingRatherThanSomethingToSay(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableMatchReasons: true));

        self::assertStringContainsString('matched_term', $prompt);
        self::assertStringContainsString('Never tell the shopper that a product "matched"', $prompt);
    }
}
