<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Swag\AssistantStarterKit\Core\Agent\ChatTurnRunnerInterface;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shopper-facing HTTP surface: `POST /assistant/chat` and `GET /assistant/history`.
 *
 * `SalesChannelContext` is injected by Shopware for a `frontend.*` route, and that is what makes
 * customer-group and rule-based prices correct for free — the reason `ARCHITECTURE.md` gives for
 * running inside Shopware rather than as an external service.
 *
 * **The order of operations in `chat()` is the design, not incidental:**
 * validate → check configuration → run (the guard fires inside `AssistantRunner`, before any spend)
 * → read the rendered cards → persist → build JSON **from the cards**.
 *
 * Every figure in the response comes from a rendered `ProductCard`. Nothing is parsed out of the
 * model's prose, which is D3 arriving at the wire: the model supplies words, the shop supplies
 * numbers. A field added here that reads anything else reopens the gap ruling R47 closed.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class AssistantController extends StorefrontController
{
    private const MAX_HISTORY_TURNS = 20;

    public function __construct(
        private readonly ChatTurnRunnerInterface $turnRunner,
        private readonly ConversationStore $conversations,
        private readonly SystemConfigLlmSettings $llmSettings,
        private readonly CardPayload $cardPayload = new CardPayload(),
    ) {}

    #[Route(
        path: '/assistant/chat',
        name: 'frontend.assistant.chat',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function chat(Request $request, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();

        $chat = ChatRequest::fromRequest($request);
        $message = $chat->message;

        if ($message === null) {
            // Rejected before anything else: a malformed request must never reach the model, both
            // because it cannot be answered and because the endpoint is public.
            return new JsonResponse(['error' => 'A non-empty "message" is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->llmSettings->isConfigured($salesChannelId)) {
            // A shop that never configured a model answers politely instead of turning a missing
            // API key into a 500. `isConfigured()` exists so this check need not catch an exception.
            return new JsonResponse([
                'error' => 'The assistant is not configured in this shop.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $token = $chat->token ?? $this->conversations->start($salesChannelId, $context->getLanguageId());
        $history = $this->conversations->history($token, self::MAX_HISTORY_TURNS);

        $result = $this->turnRunner->run($message, $salesChannelId, $history);
        $turn = $result->turn;

        // Both turns are persisted, the shopper's included: without it the replayed conversation
        // reads as the assistant talking to itself, and "add that to my cart" loses its antecedent.
        $this->conversations->append(
            $token,
            new ConversationTurn(ConversationTurn::ROLE_USER, $message),
            $result->trace,
        );
        $this->conversations->append(
            $token,
            new ConversationTurn(
                ConversationTurn::ROLE_ASSISTANT,
                $turn->prose,
                array_map(static fn($card): string => $card->id, $turn->cards),
                $turn->outcome,
            ),
            $result->trace,
        );

        return new JsonResponse([
            'token' => $token,
            'prose' => $turn->prose,
            'cards' => $this->cardPayload->of($turn->cards),
            'outcome' => $turn->outcome,
        ]);
    }

    #[Route(
        path: '/assistant/history',
        name: 'frontend.assistant.history',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function history(Request $request): Response
    {
        $token = ChatRequest::fromRequest($request)->token;

        if ($token === null) {
            return new JsonResponse(['messages' => []]);
        }

        $messages = [];
        foreach ($this->conversations->history($token, self::MAX_HISTORY_TURNS) as $turn) {
            $messages[] = [
                'role' => $turn->role,
                'prose' => $turn->prose,
                // Card ids only. Re-rendering their facts would mean re-querying the catalogue on
                // every page load, and replaying stored figures would show numbers that were true
                // when written — the UI asks again if it wants them.
                'cardIds' => $turn->cardIds,
            ];
        }

        return new JsonResponse(['messages' => $messages]);
    }
}
