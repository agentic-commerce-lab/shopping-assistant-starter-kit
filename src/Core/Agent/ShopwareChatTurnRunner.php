<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\CardResolver;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Builds a per-request agent from merchant configuration and runs one turn.
 *
 * Everything here is per-request rather than per-service, deliberately: `AssistantAgentFactory`
 * builds one `TraceRecorder` and one `FactRenderer` per turn and threads them through every tool, so
 * that the cards a tool retrieved are the cards the renderer will validate against (ruling R32 — one
 * gateway instance backs the whole request). A cached agent would leak one shopper's retrieved set
 * into another's turn, which is the single worst thing this pipeline could do.
 *
 * `cartAvailable: true` because this runs inside a storefront request with a real sales-channel
 * context. Whether the model *sees* `add_to_cart` is still the merchant's `enableAddToCart` decision,
 * enforced by the tool never being constructed — capability control is toolbox construction, never a
 * prompt instruction (D6).
 */
final readonly class ShopwareChatTurnRunner implements ChatTurnRunnerInterface
{
    public function __construct(
        private CommerceGatewayInterface $gateway,
        private SystemConfigAssistantConfig $configFactory,
        private SystemConfigLlmSettings $llmFactory,
        // Injected rather than built here, which is the whole point of the change: this is where a
        // shop's contributed tool factories arrive, having been collected by the container.
        private AssistantAgentFactory $agentFactory,
    ) {}

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
    ): TurnResult {
        $config = $this->configFactory->forSalesChannel($salesChannelId, $storefrontLocale);

        // Resolved through the same scope as any search hit, so the blocklist and the excluded
        // categories decide what the assistant may see. An id that does not resolve is dropped
        // silently — the shopper still gets an answer, just without the shortcut.
        $viewing = $viewingProductId === null ? null : $this->gateway->product($viewingProductId, $config->scope);

        $bundle = $this->agentFactory->create(
            $this->gateway,
            $config,
            cartAvailable: true,
            llm: $this->llmFactory->forSalesChannel($salesChannelId),
            viewing: $viewing,
            browsingCategoryId: $browsingCategoryId,
            recentCards: $this->lastShown($history, $config->scope),
        );

        $bundle->trace->record('page.context', [
            'reported' => $viewingProductId !== null,
            'resolved' => $viewing?->id,
            'category' => $browsingCategoryId,
        ]);

        // **Degrade, never propagate.** This is the shopper-facing path: an exception escaping here
        // is an HTTP 500 in front of a customer. Three separate pilot blockers on this branch were
        // foreseeable conditions ending the turn instead of degrading (rulings R48, R49, R52), each
        // found by a live run rather than by the suite — and on 2026-09-02 a fourth was: the catch
        // was narrowed to the agent's own exceptions, so a model endpoint answering HTML instead of
        // JSON reached the shopper as a 500.
        //
        // Both halves live in {@see FailedTurn}: which throwables degrade, the stage that records
        // why, and the apology in the conversation's own language. The trace is persisted by the
        // controller either way (A6), and no `turn.end` is synthesised (ruling R40).
        //
        // The analyzer cannot follow a closure into the callee that catches for it, so it reports
        // `AssistantRunner::run()`'s declared throw as unhandled here. It is handled — by
        // `catch (\Throwable)` one frame away, which is the whole subject of that method. A
        // `@throws` tag would silence it by making the opposite claim: that this method propagates,
        // which is precisely the behaviour the ruling above forbids. The closure is what makes the
        // degradation policy unit-testable without constructing an agent, and that is worth one
        // expected finding at its source.
        // @mago-expect analysis:unhandled-thrown-type
        $turn = FailedTurn::orDegrade(
            $bundle->trace,
            $config->defaultReplyLanguage,
            fn(): AssistantTurn => (new AssistantRunner($config, $bundle))->run($message, $this->bag($history)),
        );

        return new TurnResult($turn, $bundle->trace);
    }

    /**
     * Replays the stored conversation as chat messages.
     *
     * The card ids a previous turn rendered are **not** replayed as data — the sliding-window input
     * processor drops tool messages first for exactly that reason, and re-injecting ids would put
     * machine tokens back into shopper-facing context. What carries "that" across a page load is the
     * assistant's own prose, which named the product in words.
     *
     * @param list<ConversationTurn> $history
     */
    private function bag(array $history): MessageBag
    {
        $bag = new MessageBag();

        foreach ($history as $turn) {
            if ($turn->prose === '') {
                continue;
            }

            $bag->add(
                $turn->role === ConversationTurn::ROLE_ASSISTANT
                    ? Message::ofAssistant($turn->prose)
                    : Message::ofUser($turn->prose),
            );
        }

        return $bag;
    }

    /**
     * The cards the previous assistant reply rendered, resolved fresh.
     *
     * **Why this is needed at all.** {@see self::bag()} replays prose and deliberately not ids, so
     * without this the model reads "Club Jersey" as a name, has to search for it, and a search keyed
     * on a different term hands back a different variant of the same family. Measured on the staging
     * shop 2026-09-02: a shopper shown Blue/M got Red/XL added to their cart, announced as though it
     * were the one on screen. {@see RecentCardsContext} carries the full account.
     *
     * **Resolved, never replayed from storage.** The stored turn holds ids only, and these cards go
     * on to be rendered — so a price or a stock figure from the previous turn is exactly what this
     * project refuses to show. {@see CardResolver} re-reads them through `$scope`, in one round trip
     * where the gateway supports it, which also means a product blocked since that turn is simply
     * gone rather than resurrected by a conversation token.
     *
     * Only the most recent assistant turn. Two turns back is a shortlist the shopper has already
     * moved past, and every extra card is prompt the model pays to read on a turn that is already
     * the slowest thing in this product.
     *
     * @param list<ConversationTurn> $history
     *
     * @return list<ProductCard>
     */
    private function lastShown(array $history, CatalogScope $scope): array
    {
        foreach (array_reverse($history) as $turn) {
            if ($turn->role !== ConversationTurn::ROLE_ASSISTANT) {
                continue;
            }

            return $turn->cardIds === [] ? [] : (new CardResolver($this->gateway))->resolve($turn->cardIds, $scope);
        }

        return [];
    }
}
