<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The prompt asks for a short reply, because reply length is what a shopper waits for.
 *
 * ## Measured, on the staging shop 2026-09-02, `google/gemini-3.7-flash` via OpenRouter
 *
 * | completion tokens | wall clock |
 * |---|---|
 * | ~36  | 1.4–2.5 s |
 * | ~146 | 4.9–6.4 s |
 * | ~396 | 6.2–7.4 s |
 *
 * Generation time scales with output, and nothing else in the turn is worth optimising next to it:
 * the plugin's own retrieval measured **50–120 ms** on the same shop (`swag:assistant:benchmark`),
 * and a round trip carrying twenty tokens still costs ~2.5–3.5 s of network and provider before a
 * single word is generated. So a first turn is two round trips of floor plus however long the model
 * chooses to write — and until this constant existed, nothing anywhere told it to be brief. There is
 * no `max_tokens` either; a cap would truncate mid-sentence, which buys the seconds by breaking the
 * sentence rather than by not writing it.
 *
 * ## Why this is a quality change and not only a speed one
 *
 * The replies it replaces narrated the cards beside them: *"an all-season nylon jacket with
 * waterproof protection"* sits next to a card already showing Season, Material and Weather
 * protection. The shopper waited about six seconds to be told what they were about to read anyway.
 *
 * The wording therefore aims at **redundancy, not at brevity for its own sake** — say what the card
 * cannot, skip what it shows. A blunt "be brief" would cut the reasoning that makes a recommendation
 * useful and keep the duplication, which is the wrong half.
 */
final class SystemPromptBrevityTest extends TestCase
{
    /**
     * Anchored on the phrase itself rather than on `/short|brief|concise/`, which passed before the
     * rule existed: `CLOSING` already contains *"too short to tell"*, so the loose pattern was
     * asserting nothing. A test that cannot fail is worse than no test.
     */
    public function testTheRulesAskForAShortReply(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('two or three sentences', $prompt);
    }

    /**
     * The substance of the instruction. Cards carry name, price, stock, options and properties, and
     * repeating any of that is the six seconds this constant exists to stop being spent.
     */
    public function testItNamesTheCardsAsTheThingNotToRepeat(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('do not repeat what they show', $prompt);
    }

    /**
     * **Style guidance outranks nothing, but it must still be able to override this.** A merchant who
     * writes "explain your reasoning in detail" in `agentVoice` has made a deliberate trade of
     * seconds for depth, and the shipped default must not silently win over it — the voice field is
     * appended last for exactly that reason, and this test pins the ordering rather than the effect.
     */
    public function testMerchantVoiceStillComesAfterTheBrevityRule(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(agentVoice: 'Explain your reasoning at length.'));

        $brevity = mb_strpos($prompt, 'do not repeat what they show');
        $voice = mb_strpos($prompt, 'Explain your reasoning at length.');

        // Both `assertIsInt` calls matter: `mb_strpos` returns false when absent, and `false > 0` is
        // false while `(int) false` is 0 — either way a missing rule would have made the comparison
        // below pass for the wrong reason. That is exactly how the first version of this test
        // asserted nothing at all.
        self::assertIsInt($brevity, 'the brevity rule is missing from the prompt');
        self::assertIsInt($voice);
        self::assertGreaterThan($brevity, $voice, 'the merchant must be able to overrule the default');
    }
}
