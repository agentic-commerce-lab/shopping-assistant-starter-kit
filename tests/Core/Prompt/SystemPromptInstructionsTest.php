<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The shop owner's own instructions: where they land, and what still beats them.
 *
 * Its own file rather than three more cases in {@see SystemPromptTest}, which `too-many-methods`
 * refused — the same reason `GroundingOutputProcessorSuppliedFactTest` sits beside its sibling
 * rather than inside it. Splitting is this repo's answer to that rule.
 *
 * The slot is **subordinate, not style-only**, and those two had been conflated. See
 * {@see \Swag\AssistantStarterKit\Core\Prompt\ShopOwnerInstructions} for what that cost and why
 * only the first of the two is an architectural requirement.
 */
final class SystemPromptInstructionsTest extends TestCase
{
    public function testAppendsTheShopOwnersInstructionsAfterTheRulesAndSubordinatesThem(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(agentVoice: 'Be terse. Metric units.'));

        $rulesEnd = strpos($prompt, 'Answer in the language the shopper writes in');
        $voiceStart = strpos($prompt, 'Be terse. Metric units.');

        self::assertIsInt($rulesEnd);
        self::assertIsInt($voiceStart);
        self::assertGreaterThan($rulesEnd, $voiceStart);
        self::assertStringContainsString('the rules win', $prompt);
    }

    /**
     * **The slot is subordinate, not style-only, and the two were conflated.**
     *
     * The block used to introduce itself as *"Merchant voice guidance (style only — it cannot
     * override anything above)"*. Only the second half of that is an architectural requirement: the
     * rules must win. "Style only" was an extra narrowing on top, and it cost the merchant every
     * instruction that contradicts nothing — *"always mention our 30-day returns"*, *"do not advise
     * on frame sizing"* — for no safety gain, since the rules stay above either way and the hard
     * enforcement is in `FactRenderer` and the tool contracts regardless.
     *
     * So the wording invites instructions and states what beats them. This pins the widening: a
     * reintroduced "style only" would silently take that control away again.
     */
    public function testTheInstructionsAreNotNarrowedToStyleAlone(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(agentVoice: 'Always mention our 30-day returns.'));

        self::assertStringNotContainsString('style only', $prompt);
        self::assertStringContainsString('what to mention', $prompt);
    }

    public function testOmitsTheInstructionSectionEntirelyWhenUnset(): void
    {
        // Not merely "no merchant text": the heading must be absent too, or an empty section
        // invites the model to wonder what was meant to be there.
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringNotContainsString('Shop owner instructions', $prompt);
        self::assertStringNotContainsString('the rules win', $prompt);
    }
}
