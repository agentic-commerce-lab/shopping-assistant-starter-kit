<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentExceptionInterface;
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

    public function run(
        string $message,
        string $salesChannelId,
        array $history,
        ?string $viewingProductId = null,
        ?string $browsingCategoryId = null,
    ): TurnResult {
        $config = $this->configFactory->forSalesChannel($salesChannelId);

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
        );

        $bundle->trace->record('page.context', [
            'reported' => $viewingProductId !== null,
            'resolved' => $viewing?->id,
            'category' => $browsingCategoryId,
        ]);

        try {
            $turn = (new AssistantRunner($config, $bundle))->run($message, $this->bag($history));
        } catch (AgentExceptionInterface) {
            // **Degrade, never propagate.** This is the shopper-facing path: an exception escaping
            // here is an HTTP 500 in front of a customer. Three separate pilot blockers on this
            // branch were foreseeable conditions ending the turn instead of degrading (rulings R48,
            // R49, R52), each found by a live run rather than by the suite — so a fourth is not
            // being shipped.
            //
            // The trace is kept and returned: it is what says where the turn stopped, and it is
            // persisted by the controller either way (A6). The shopper sees a fixed, honest
            // sentence rather than the exception message — a model or network error is not
            // something to explain to a customer, and the message could carry internals.
            $turn = new AssistantTurn(
                'Sorry — I could not finish that just now. Please try again in a moment.',
                [],
                'error',
            );
        }

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
}
