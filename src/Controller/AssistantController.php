<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\ChatTurnRunnerInterface;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Policy\BudgetVerdict;
use Swag\AssistantStarterKit\Core\Policy\RequestBudget;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\Sink\TraceSinkDispatcher;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
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
 * validate → check configuration → spend the request budget (`RequestBudget`, before the first
 * database call) → run (the guard fires inside `AssistantRunner`, before any model spend) → read the
 * rendered cards → persist → build JSON **from the cards**.
 *
 * Every figure in the response comes from a rendered `ProductCard`. Nothing is parsed out of the
 * model's prose, which is D3 arriving at the wire: the model supplies words, the shop supplies
 * numbers. A field added here that reads anything else reopens the gap ruling R47 closed.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class AssistantController extends StorefrontController
{
    private const MAX_HISTORY_TURNS = 20;

    // @mago-expect lint:excessive-parameter-list
    // A dependency-injected constructor, not a call signature: each entry is one collaborator the
    // container supplies once, and grouping them behind a holder object would hide what this
    // controller depends on without reducing it.
    public function __construct(
        private readonly ChatTurnRunnerInterface $turnRunner,
        private readonly ConversationStore $conversations,
        private readonly SystemConfigLlmSettings $llmSettings,
        private readonly SystemConfigAssistantConfig $assistantConfig,
        // Required, not defaulted. Every other collaborator here could sensibly fall back to a
        // stock instance; a rate limiter cannot, because the fallback would be "no limit" on a
        // public endpoint that spends money — and a control that quietly does nothing is the exact
        // defect {@see RequestBudget} was written to fix.
        private readonly RequestBudget $budget,
        private readonly CardPayload $cardPayload = new CardPayload(),
        private readonly HandoffPayload $handoff = new HandoffPayload(),
        // Defaulted to an empty dispatcher so a shop with no sinks configured pays nothing and
        // needs no wiring; the container passes the tagged ones.
        private readonly TraceSinkDispatcher $traceSinks = new TraceSinkDispatcher([]),
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

        $config = $this->assistantConfig->forSalesChannel($salesChannelId);

        // **Before the first database call, and in every branch below it.** This is the abuse
        // defence: a throttle that first writes a row is an amplifier rather than a defence, and a
        // branch that skips it is an unthrottled path.
        $refusal = $this->budget->consumeClientWindow($config, ClientKey::of($request, $salesChannelId));

        // The daily budget is the merchant's spend ceiling, and it is consumed only when a turn
        // could actually spend. A switched-off assistant is reported by `GuardCheck` inside the
        // runner, which records `kill_switch` in the trace as that setting's help text promises;
        // consuming the budget here first would answer "out of budget" to a shop that is simply off
        // — the wrong reason, and one no trace would ever show.
        if ($refusal->accepted && $config->assistantEnabled) {
            $refusal = $this->budget->consumeDailyBudget($config, $salesChannelId);
        }

        if (!$refusal->accepted) {
            return $this->refuse($refusal);
        }

        $token = $chat->token ?? $this->conversations->start(
            $salesChannelId,
            $context->getLanguageId(),
            // Recorded once, on the turn that opens the conversation. A conversation resumed by
            // token never re-reads this, so logging in mid-conversation does not rewrite who it
            // belonged to — the column answers "who produced this trace", not "who was last seen".
            $context->getCustomer()?->getId(),
        );
        $history = $this->conversations->history($token, self::MAX_HISTORY_TURNS);

        $result = $this->turnRunner->run(
            $message,
            $salesChannelId,
            $history,
            $chat->page->productId,
            $chat->page->categoryId,
        );
        $turn = $result->turn;

        // Both messages are stored, the shopper's included: without it the replayed conversation
        // reads as the assistant talking to itself, and "add that to my cart" loses its antecedent.
        //
        // **The trace belongs to exactly one of them.** Passing it to both wrote every event twice —
        // measured in the real shop, where one turn produced two identical rows per event, so a
        // merchant counting tool calls counted double. There is one trace per turn, and it is the
        // assistant's turn that produced it.
        // One clock for both turns, so a stored conversation cannot show the reply arriving before
        // the question that caused it.
        $now = new \DateTimeImmutable();

        $this->conversations->append(
            $token,
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: $message, createdAt: $now),
            new TraceRecorder(),
        );
        $this->conversations->append(
            $token,
            new ConversationTurn(
                role: ConversationTurn::ROLE_ASSISTANT,
                prose: $turn->prose,
                cardIds: array_map(static fn($card): string => $card->id, $turn->cards),
                outcome: $turn->outcome,
                createdAt: $now,
                // Persisted with the turn, not merely returned: without this a page reload restores
                // the misleading sentence with no correction beside it.
                warnings: self::warnings($turn),
            ),
            $result->trace,
        );

        // **After persistence, never before.** A sink is third-party code, and one that throws must
        // not cost the merchant the audit row that was the point of recording the turn. The dispatcher
        // isolates each sink; see TraceSinkDispatcher for what a failure does instead.
        $this->traceSinks->dispatch($token, $salesChannelId, $result->trace);

        return new JsonResponse([
            'token' => $token,
            'prose' => $turn->prose,
            'cards' => $this->cardPayload->of($turn->cards),
            'outcome' => $turn->outcome,
            // Rendered from the outcome and the merchant's settings, never from the prose beside it
            // — the model has never seen this URL, so it cannot have got it wrong.
            'handoff' => $this->handoff->of($turn->outcome, $config),
            // Where the prose contradicts the cards, said out loud rather than logged and forgotten.
            // A client that renders the reply verbatim needs to know: a live turn told a shopper
            // "the Trail Jersey is available in Blue, size M" beside a card reporting stock 0
            // (ruling R75). The cards are always authoritative; this says when the sentence beside
            // them is not, so the interface can annotate it, de-emphasise it, or drop it.
            'warnings' => self::warnings($turn),
        ]);
    }

    /**
     * A request refused by {@see RequestBudget}: 429, nothing written, nothing spent.
     *
     * `Retry-After` is the point of answering rather than dropping the connection — without it a
     * client has nothing to back off by, and the widget's own retry button becomes a way to hammer
     * the endpoint that just refused it. The reason code is included because a merchant debugging a
     * quiet assistant needs to tell "this shopper is too fast" from "the shop's daily budget is
     * gone"; neither tells an abuser anything they cannot already see from the status code.
     */
    private function refuse(BudgetVerdict $refusal): JsonResponse
    {
        $message = $refusal->reasonCode === RequestBudget::REASON_DAILY_CAP
            ? 'The assistant has reached this shop\'s request limit for now.'
            : 'Too many messages in a short time. Please wait a moment and try again.';

        return new JsonResponse(
            ['error' => $message, 'reason' => $refusal->reasonCode],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $refusal->retryAfterSeconds],
        );
    }

    /**
     * The one place the warning shape is built, so what a client receives live and what it receives
     * from history cannot drift apart.
     *
     * @return array<string, list<string>>
     */
    private static function warnings(AssistantTurn $turn): array
    {
        return [
            'unbackedPrices' => $turn->warnings->unbackedPrices,
            'unbackedAvailabilityClaims' => $turn->warnings->unbackedAvailabilityClaims,
            'unbackedPropertyClaims' => $turn->warnings->unbackedPropertyClaims,
        ];
    }

    #[Route(
        path: '/assistant/history',
        name: 'frontend.assistant.history',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function history(Request $request, SalesChannelContext $context): Response
    {
        $token = ChatRequest::fromRequest($request)->token;

        if ($token === null) {
            return new JsonResponse(['messages' => []]);
        }

        // Read once for the whole transcript rather than per turn: every turn in one conversation
        // belongs to one sales channel, so a per-turn read would be the same answer N times.
        $config = $this->assistantConfig->forSalesChannel($context->getSalesChannelId());

        $messages = [];
        foreach ($this->conversations->history($token, self::MAX_HISTORY_TURNS) as $turn) {
            $messages[] = [
                'role' => $turn->role,
                'prose' => $turn->prose,
                // Card ids only. Re-rendering their facts would mean re-querying the catalogue on
                // every page load, and replaying stored figures would show numbers that were true
                // when written — the UI asks again, at `GET /assistant/cards`.
                'cardIds' => $turn->cardIds,
                // Null for a turn stored before this field existed. A client must render no
                // timestamp for that rather than substituting the current time, which would present
                // a figure this server never produced as fact.
                'createdAt' => $turn->createdAt?->format(\DATE_ATOM),
                // Unlike the figures, a warning does not go stale: it describes what that reply said.
                'warnings' => $turn->warnings,
                // Rebuilt, not replayed: if the merchant has since changed where escalation points —
                // or switched it off — the reloaded transcript must offer what works now, not what
                // worked then.
                'handoff' => $this->handoff->of($turn->outcome, $config),
            ];
        }

        return new JsonResponse(['messages' => $messages]);
    }
}
