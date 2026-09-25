<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * What the model is told about the shopper's own orders, and only when it has a tool to read them.
 *
 * Keyed to {@see AssistantConfig::$enableOrderHistory} like every block in {@see CapabilityRules},
 * with one difference that matters: {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner}
 * narrows that flag to what the turn's toolbox actually holds before the prompt is built. The
 * merchant's switch alone is not enough — a guest, or a gateway that cannot read orders, gets no order
 * tool, and telling that model to answer order questions with tools it cannot see would have it
 * improvise about somebody's order, which is the failure escalation exists to prevent.
 *
 * ## Why these exist — 2026-09-24
 *
 * Staging traces with order history on and a shopper signed in: asked for the status of their latest
 * orders, the model listed them and then escalated; asked for the total spent, it said the shop shows
 * the totals so it could not. It was doing what it was told. The rules forbid stating any figure, and
 * the escalation clause sends "access customer accounts" to a human. So D3 was relaxed for the
 * shopper's own orders — see {@see \Swag\AssistantStarterKit\Core\Tool\ToolOrderFacts} — and these
 * two blocks carry that relaxation into the prompt. Without order tools the prompt is byte-for-byte
 * what it was, which `SystemPromptOrderHistoryTest` asserts.
 */
final class OrderRules
{
    /**
     * Placed directly under "Never state a price…" by {@see SystemPrompt::build()}, because it is an
     * exception to that sentence and an exception read far from its rule is two rules to reconcile.
     *
     * Narrow on purpose: the figure as it was returned — "added up" included, since a sum is
     * arithmetic of the model's own and nothing backs it — and only for an order a tool returned. A
     * catalogue price stays under the rule above it, unchanged.
     */
    public const OWN_ORDER_FIGURES = <<<'PROMPT'
        The shopper's own orders are the one exception. A date, a state, a total, a quantity or a
        price that an order tool returned this turn, you may state for that order exactly as it was
        returned: never rounded, converted, added up or guessed, and never for an order the tool did
        not return.
        PROMPT;

    /**
     * Appended to the escalation clause, after the checkout carve-out, for that carve-out's reason:
     * "any of those" must stay in one breath with the list it points back at, and this narrows that
     * list. Worded without the word "escalate" so the same sentence serves the branch where no
     * escalate tool was constructed.
     *
     * What stays on the list is what the order tools cannot do: a return, a cancellation, a complaint
     * or a change to the account needs a person, and a missing delivery is a complaint the state label
     * cannot answer.
     */
    public const ANSWERED_WITH_THE_ORDER_TOOLS =
        ' A question about the status, date, total or contents of their own orders is not one of those'
            . ' either: answer it with the order tools. Returns, cancellations, complaints, a missing'
            . ' delivery and changes to their account still are.';

    private function __construct() {}

    /**
     * {@see self::OWN_ORDER_FIGURES} with the blank line that separates it, or nothing.
     *
     * The branch lives here rather than in {@see SystemPrompt::build()} because mago sums cyclomatic
     * complexity per class and that class had no budget left for two more conditions.
     */
    public static function afterFigureRule(AssistantConfig $config): string
    {
        return $config->enableOrderHistory ? "\n\n" . self::OWN_ORDER_FIGURES : '';
    }

    /** {@see self::ANSWERED_WITH_THE_ORDER_TOOLS}, or nothing — the same gate, for the same reason. */
    public static function afterEscalationClause(AssistantConfig $config): string
    {
        return $config->enableOrderHistory ? self::ANSWERED_WITH_THE_ORDER_TOOLS : '';
    }
}
