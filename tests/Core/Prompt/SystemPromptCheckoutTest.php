<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The three rules a merchant's live report on 2026-09-03 traced back to this prompt.
 *
 * All three are unconditional, and that is deliberate. `ESCALATION_AVAILABLE` is gated on config
 * because it *instructs a tool call*, and telling a model to use a tool that was never constructed
 * is worse than saying nothing — the reasoning is in {@see \Swag\AssistantStarterKit\Core\Tool\EscalateTool}.
 * These three instruct no tool: two are prohibitions and one withdraws an instruction. They are
 * true whether or not `go_to_checkout` exists, so nothing here can ever name a tool that does not.
 * The positive "call go_to_checkout" instruction lives in the tool's own description, which is in
 * the schema exactly when the tool is.
 */
final class SystemPromptCheckoutTest extends TestCase
{
    public function testCheckoutIsNotEscalated(): void
    {
        // The reported bug: the rules end on "you cannot create orders, take payment", the clause
        // after them says "if asked about any of those, escalate", and so "I want to go to
        // checkout" reached the shopper as the merchant's contact page.
        $prompt = SystemPrompt::build(new AssistantConfig(enableEscalation: true));

        self::assertStringContainsString('go to checkout is not one of those things', $prompt);
        self::assertStringContainsString('Never escalate it.', $prompt);
        // Still adjacent to its own antecedent: "any of those" and the list it refers to have to
        // stay in one breath, which is why the carve-out joins the clause instead of the rules.
        self::assertStringContainsString(
            "or access customer accounts.\nIf asked about any of those, escalate.",
            $prompt,
        );
    }

    public function testTheModelIsToldItDoesNotKnowTheCart(): void
    {
        // The second reported bug. The shopper filled the cart with a card's own button, which
        // posts to Shopware and tells this plugin nothing, and the model then answered from the
        // only cart history it had — its own.
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString("never told what is in the shopper's cart", $prompt);
        self::assertStringContainsString('never say the', $prompt);
    }

    public function testALinkMayNotBePromisedUnlessAToolPromisedOne(): void
    {
        // "Here is a link to the checkout" with no link under it. A blanket ban would contradict
        // the escalate tool, whose note does legitimately instruct the model to announce one — so
        // the rule turns on whether a tool said so.
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('unless a tool has told you one does', $prompt);
    }

    public function testTheEscalationClauseItselfIsUnchangedAndStillConditional(): void
    {
        $withTool = SystemPrompt::build(new AssistantConfig(enableEscalation: true));
        $without = SystemPrompt::build(new AssistantConfig(enableEscalation: false));

        self::assertStringContainsString('If asked about any of those, escalate.', $withTool);
        self::assertStringNotContainsString('If asked about any of those, escalate.', $without);
        // The carve-out is not conditional, so a shop with escalation switched off still gets it:
        // the other branch says "say plainly that you cannot help with it here", which is just as
        // wrong an answer to "take me to checkout".
        self::assertStringContainsString('go to checkout is not one of those things', $without);
        // And the word itself is still absent there, which the carve-out must not undo: an
        // instruction naming a tool the model cannot see is worse than no instruction.
        self::assertStringNotContainsStringIgnoringCase('escalate', $without);
    }
}
