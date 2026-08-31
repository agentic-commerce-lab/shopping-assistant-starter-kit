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
     * @param list<ConversationTurn> $history          oldest first
     * @param ?string                $viewingProductId the product the storefront reports open, or null.
     *                                                 A **hint**: the implementation resolves it through
     *                                                 the catalogue scope and ignores what does not resolve.
     * @param ?string $browsingCategoryId the category the storefront reports being browsed, or null.
     *                                    Never resolved — it only narrows a search, and narrowing is
     *                                    safe by construction (P8).
     * @param ?string $storefrontLocale the language this storefront presents itself in, from the
     *                                  request's domain, or null when the caller is not a storefront
     *                                  request. Only ever the FALLBACK language: the reply follows
     *                                  the shopper's own words, and this is what the shop opens with
     *                                  when a first message has no language in it.
     */
    // @mago-expect lint:excessive-parameter-list
    // Six facts about one turn, with two provenances that must not be merged: `message`,
    // `viewingProductId` and `browsingCategoryId` are what the CLIENT claimed and are treated as
    // hints throughout, while `salesChannelId` and `storefrontLocale` are what the SERVER knows from
    // the request it is already handling. The grouping the rule asks for would put those two kinds
    // in one object, and this pipeline's whole posture is that the difference between them decides
    // how much a value is trusted.
    public function run(
        string $message,
        string $salesChannelId,
        array $history,
        ?string $viewingProductId = null,
        ?string $browsingCategoryId = null,
        ?string $storefrontLocale = null,
    ): TurnResult;
}
