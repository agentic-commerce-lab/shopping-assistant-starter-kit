<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\GuardCheck;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Drives one turn of the conversation: the guard check that must happen
 * before any spend, the system prompt, the framework's own tool-calling loop
 * (via the {@see Bundle}'s agent), and reading back what the grounding
 * pipeline produced. Outcome/activity bookkeeping is delegated to
 * {@see TurnOutcomeResolver} and {@see TurnToolCallCounter}.
 *
 * {@see BoundedToolbox}'s tool-call cap throws {@see MaxIterationsExceededException}
 * once a turn's model keeps requesting tool calls past `maxToolCallsPerTurn` — a
 * foreseeable condition (a confused model looping), not a server fault, so
 * {@see self::run()} catches it and degrades to a normal {@see AssistantTurn}
 * rather than letting it become a 500. Same defect class, and the same
 * "degrade, don't abort" fix, as the malformed-tool-argument and denormalization
 * failures {@see BoundedToolbox} itself already absorbs.
 */
final class AssistantRunner
{
    /**
     * Fixed, honest prose for a turn {@see self::run()} could not finish. Deliberately
     * makes no claim about any product, price or availability — the only cards this
     * reply can carry are whatever {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
     * already retrieved before the cap was hit, never anything this sentence itself
     * asserts.
     */
    private const INCOMPLETE_TURN_MESSAGE =
        'I was not able to finish handling that request. '
            . 'Could you narrow it down — for example, ask about one product at a time?';

    public function __construct(
        private readonly AssistantConfig $config,
        private readonly Bundle $bundle,
        private readonly TurnOutcomeResolver $outcomeResolver = new TurnOutcomeResolver(),
        private readonly TurnToolCallCounter $activityCounter = new TurnToolCallCounter(),
    ) {}

    /**
     * @throws \Symfony\AI\Agent\Exception\ExceptionInterface propagated from the
     *         platform call; the guard above is the only thing this method can do
     *         before that point, and {@see MaxIterationsExceededException} is the
     *         only foreseeable member of this hierarchy this method catches instead
     *         of propagating — see {@see self::incompleteTurn()}
     */
    public function run(string $message, MessageBag $history): AssistantTurn
    {
        $decision = (new GuardCheck())->check($this->config);

        if ($decision->isBlocked()) {
            $this->bundle->trace->record('guard.check', [
                'verdict' => 'block',
                'reasonCode' => $decision->reasonCode,
            ]);

            // Blocked: the platform must never be touched, so nothing below this
            // point runs — not even message-bag assembly.
            return new AssistantTurn($decision->message, [], 'error');
        }

        $this->bundle->trace->record('guard.check', [
            'verdict' => 'allow',
            'reasonCode' => $decision->reasonCode,
        ]);

        // Before the model runs: the price audit needs to know which figures the SHOPPER introduced,
        // so it does not flag the model for restating them (ruling R85).
        $this->bundle->renderer->registerShopperMessage($message);

        try {
            $result = $this->bundle->agent->call($this->buildMessageBag($message, $history));
        } catch (MaxIterationsExceededException) {
            // A confused model that keeps requesting tool calls is a foreseeable
            // condition, not a server fault — BoundedToolbox's cap firing is this
            // class working as designed. Degrade to a normal AssistantTurn instead
            // of letting this escape as an uncaught 500.
            return $this->incompleteTurn();
        }

        $cards = $this->bundle->renderer->renderedCards();
        $unbackedPrices = $this->bundle->renderer->unbackedPrices();
        $unbackedAvailability = $this->bundle->renderer->unbackedAvailability();

        $outcome = $this->outcomeResolver->outcome($this->bundle->trace, $cards);

        $this->recordTurnEnd($outcome, $cards);

        $prose = $result instanceof TextResult ? $result->getContent() : '';

        return new AssistantTurn($prose, $cards, $outcome, $unbackedPrices, $unbackedAvailability);
    }

    /**
     * Builds the degraded {@see AssistantTurn} for a turn {@see MaxIterationsExceededException}
     * cut short. No {@see TextResult} was ever produced, so {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor}
     * never ran and {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::render()}
     * was never called this turn — `renderedCards()` would just read back an empty
     * default, silently discarding cards the tools genuinely retrieved. Rendering
     * `lastRetrievedBatch()` here mirrors that processor's own fallback default (see
     * its docblock) rather than reaching further back into every id retrieved this
     * turn, so this stays "whatever the last successful tool call actually returned",
     * exactly like the success path.
     */
    private function incompleteTurn(): AssistantTurn
    {
        $cards = $this->bundle->renderer->render($this->bundle->renderer->lastRetrievedBatch());

        $this->bundle->trace->record('turn.tool_limit_exceeded', [
            'maxToolCallsPerTurn' => $this->config->maxToolCallsPerTurn,
        ]);

        $this->recordTurnEnd(TurnOutcomeResolver::TOOL_LIMIT_EXCEEDED, $cards);

        return new AssistantTurn(self::INCOMPLETE_TURN_MESSAGE, $cards, TurnOutcomeResolver::TOOL_LIMIT_EXCEEDED);
    }

    /**
     * @param list<ProductCard> $cards
     */
    private function recordTurnEnd(string $outcome, array $cards): void
    {
        $this->bundle->trace->record('turn.end', [
            'outcome' => $outcome,
            'cards' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
            'toolCalls' => $this->activityCounter->count($this->bundle->trace),
        ]);
    }

    private function buildMessageBag(string $message, MessageBag $history): MessageBag
    {
        $prompt = $this->bundle->prompt->system($this->config, $this->bundle->vocabulary, $this->bundle->viewing);

        // **Recorded in full, every turn, and not behind a setting.** Every other stage of the turn
        // was already traced; this was the one thing a merchant could not see when the assistant said
        // something wrong. A switch would not help — the turn that went wrong has already happened.
        //
        // No shopper text is involved: the system message is built from merchant config and the
        // catalogue's own facet vocabulary, and the shopper's words are appended below it.
        //
        // The hash makes "did the prompt change between these two turns" answerable without diffing
        // three kilobytes by eye — which is a question `PromptProviderInterface` created, since the
        // prompt can now come from another plugin entirely.
        $this->bundle->trace->record('prompt', [
            'text' => $prompt,
            'sha256' => hash('sha256', $prompt),
            'length' => mb_strlen($prompt),
        ]);

        $bag = new MessageBag(Message::forSystem($prompt));

        foreach ($history->getMessages() as $historyMessage) {
            $bag->add($historyMessage);
        }

        $bag->add(Message::ofUser($message));

        return $bag;
    }
}
