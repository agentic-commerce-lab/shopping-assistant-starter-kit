<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\OrderRules;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * What the prompt says about the shopper's own orders, and that it says nothing when there are no
 * order tools.
 *
 * Staging traces from 2026-09-24: a signed-in shopper asked for the status of their latest orders,
 * the order cards showed "Open", and the model escalated because "order status details require human
 * support". Nothing in the prompt said otherwise — its only order rule was the list of things the
 * assistant cannot do, closed by "if asked about any of those, escalate".
 *
 * `enableOrderHistory` here means what {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner}
 * passes: whether this turn's toolbox holds an order tool, not merely whether the merchant switched
 * it on. A guest on a shop with order history enabled gets the prompt below with the flag off.
 */
final class SystemPromptOrderHistoryTest extends TestCase
{
    /**
     * Without order tools the prompt must be exactly what it was — `order_status_escalates` pins the
     * escalating behaviour on it, and an instruction about tools the model cannot see is worse than
     * none. Asserted as "the only difference is the two blocks", so the base can keep evolving.
     */
    public function testTheOrderBlocksAreTheOnlyDifference(): void
    {
        foreach ([true, false] as $escalation) {
            $without = SystemPrompt::build(new AssistantConfig(enableEscalation: $escalation));
            $with = SystemPrompt::build(new AssistantConfig(enableEscalation: $escalation, enableOrderHistory: true));

            self::assertStringNotContainsString(OrderRules::OWN_ORDER_FIGURES, $without);
            self::assertStringNotContainsString(OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS, $without);
            self::assertNotSame($without, $with, 'otherwise the comparison below proves nothing');
            self::assertSame($without, str_replace(
                search: ["\n\n" . OrderRules::OWN_ORDER_FIGURES, OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS],
                replace: '',
                subject: $with,
            ));
        }
    }

    /**
     * The carve-out sits directly under the rule it carves out of. Stated anywhere else, "never state a
     * price" and "you may state an order's total" are two rules a model has to reconcile on its own.
     */
    public function testTheFigureExceptionFollowsTheFigureRule(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableOrderHistory: true));

        self::assertStringContainsString(
            "leave all numbers to\nthe shop.\n\n" . OrderRules::OWN_ORDER_FIGURES,
            $prompt,
        );
    }

    /** Exactly as returned, and only for an order a tool returned — the two edges of the relaxation. */
    public function testTheExceptionIsNarrow(): void
    {
        // One line per sentence here, however the constant happens to be wrapped.
        $rule = preg_replace('/\s+/', replacement: ' ', subject: OrderRules::OWN_ORDER_FIGURES) ?? '';

        self::assertStringContainsString('exactly as it was returned', $rule);
        self::assertStringContainsString('never rounded', $rule);
        self::assertStringContainsString('never for an order the tool did not return', $rule);
    }

    /**
     * Order questions leave the escalation list; returns and complaints stay on it. The clause joins
     * the escalation sentence for the checkout carve-out's reason: "any of those" has to stay in one
     * breath with the list it points at.
     */
    public function testOrderQuestionsAreAnsweredAndReturnsStillEscalate(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableEscalation: true, enableOrderHistory: true));

        self::assertStringContainsString(
            "or access customer accounts.\nIf asked about any of those, escalate.",
            $prompt,
        );
        self::assertStringContainsString('Never escalate it.' . OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS, $prompt);
        self::assertStringContainsString('status', OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS);
        self::assertStringContainsString(
            'Returns, cancellations, complaints',
            OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS,
        );
    }

    /** With escalation off the word must still not appear: there is no tool to call by that name. */
    public function testTheClauseNamesNoEscalationWhenThereIsNone(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableEscalation: false, enableOrderHistory: true));

        self::assertStringContainsString(OrderRules::ANSWERED_WITH_THE_ORDER_TOOLS, $prompt);
        self::assertStringNotContainsStringIgnoringCase('escalate', $prompt);
    }
}
