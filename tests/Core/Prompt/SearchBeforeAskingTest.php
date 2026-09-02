<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The rule that tells a cautious model to look something up instead of asking whether the shopper
 * meant it.
 *
 * ## Why it is conditional
 *
 * A blanket "call a tool before you answer" was already tried and measured, and it cost ten seconds
 * a turn. From `tests/Journeys/page_context_no_lookup.php`:
 *
 * ```
 * wording v1 ("… use your tools")       2 round trips   1 tool call    13 838 ms
 * wording v2 ("its card is already …")  1 round trip    0 tool calls    3 656 ms
 * ```
 *
 * That journey asserts `tool_calls_at_most: 0` and is green on both `google/gemini-3.7-flash` and
 * `openai/gpt-5-mini` (measured 2026-09-02). A shopper standing on a product page has already been
 * handed that product; sending the model to search for it again is pure latency.
 *
 * So the rule is gated on `$viewing` being empty — no product page, nothing pre-grounded, and
 * therefore nothing to lose. `page_context_no_lookup` never sees the sentence at all, which is why
 * that measurement cannot regress.
 *
 * ## Why it is expected not to move the models that already work
 *
 * It states what Gemini already does. Measured 2026-09-02 across five journeys: `renders_at_least`
 * 3/3, `tool_calls_at_least` implicit, `questions_at_most` within limit everywhere. A rule a model
 * already satisfies cannot change its behaviour by being written down — but it can give a model that
 * does *not* satisfy it the instruction it was missing. `openai/gpt-5-mini` on
 * `fashion_specific_request_no_interrogation` asked three confirming questions in a row
 * ("Would you like me to check whether it comes in size M?") about a size the shopper had named in
 * their first message.
 *
 * That expectation is a hypothesis, and the eval baseline is what tests it. This class only pins the
 * two structural properties a unit test can hold.
 */
#[CoversClass(SystemPrompt::class)]
final class SearchBeforeAskingTest extends TestCase
{
    public function testTheRuleIsPresentWhenTheShopperIsNotOnAProductPage(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('Search before you answer', $prompt);
        self::assertStringContainsString('already told you', $prompt);
    }

    /**
     * The whole point of the gate: with a product already in context, `page_context_no_lookup`'s
     * measurement must be untouched.
     */
    public function testTheRuleIsAbsentWhenAProductIsAlreadyInContext(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(), '', 'The shopper is looking at the Trail Jersey.');

        self::assertStringNotContainsString('Search before you answer', $prompt);
    }

    /**
     * Everything else must be byte-identical, so a diff of the two prompts is exactly this rule and
     * nothing else. A prompt change that also moved an unrelated paragraph would make the eval
     * comparison meaningless.
     */
    public function testItAddsTheRuleAndChangesNothingElse(): void
    {
        $withRule = SystemPrompt::build(new AssistantConfig());
        $withViewing = SystemPrompt::build(new AssistantConfig(), '', 'VIEWING-LINE');

        $stripped = str_replace("\n\nVIEWING-LINE", '', $withViewing);
        $rule = str_replace($stripped, '', $withRule);

        // The difference is one contiguous block, not scattered edits.
        self::assertNotSame('', trim($rule));
        self::assertSame($stripped, str_replace($rule, '', $withRule));
    }
}
