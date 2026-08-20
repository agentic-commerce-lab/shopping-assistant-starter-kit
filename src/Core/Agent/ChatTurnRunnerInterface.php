<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;

/**
 * Runs one shopper message through the pipeline.
 *
 * An interface so the controller can be tested without an LLM, a network, or a Shopware kernel. That
 * is not a convenience: the controller's job is **ordering** — validate, then guard, then spend, then
 * persist, then render — and every one of those steps has a failure mode that must be observable
 * without an API key. Three pilot blockers on this branch were foreseeable conditions ending a turn
 * instead of degrading (rulings R48, R49, R52), and each was found by a live run because nothing
 * cheaper could see them.
 */
interface ChatTurnRunnerInterface
{
    /**
     * @param list<ConversationTurn> $history oldest first
     */
    public function run(string $message, string $salesChannelId, array $history): TurnResult;
}
