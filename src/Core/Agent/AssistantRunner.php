<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\GuardCheck;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Drives one turn of the conversation: the guard check that must happen
 * before any spend, the system prompt, the framework's own tool-calling loop
 * (via the {@see Bundle}'s agent), and reading back what the grounding
 * pipeline produced. Outcome/tool-call bookkeeping is delegated to
 * {@see TurnOutcomeResolver}.
 */
final class AssistantRunner
{
    public function __construct(
        private readonly AssistantConfig $config,
        private readonly Bundle $bundle,
        private readonly int $requestsToday = 0,
        private readonly TurnOutcomeResolver $outcomeResolver = new TurnOutcomeResolver(),
        private readonly TurnToolCallCounter $toolCallCounter = new TurnToolCallCounter(),
    ) {}

    /**
     * @throws \Symfony\AI\Agent\Exception\ExceptionInterface propagated from the
     *         platform call; the guard above is the only thing this method can do
     *         before that point, so nothing here is left to catch it usefully
     */
    public function run(string $message, MessageBag $history): AssistantTurn
    {
        $decision = (new GuardCheck())->check($this->config, $this->requestsToday);

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

        $result = $this->bundle->agent->call($this->buildMessageBag($message, $history));

        $cards = $this->bundle->renderer->renderedCards();
        $unbackedPrices = $this->bundle->renderer->unbackedPrices();

        $outcome = $this->outcomeResolver->outcome($this->bundle->trace, $cards);

        $this->bundle->trace->record('turn.end', [
            'outcome' => $outcome,
            'cards' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
            'toolCalls' => $this->toolCallCounter->count($this->bundle->trace),
        ]);

        $prose = $result instanceof TextResult ? $result->getContent() : '';

        return new AssistantTurn($prose, $cards, $outcome, $unbackedPrices);
    }

    private function buildMessageBag(string $message, MessageBag $history): MessageBag
    {
        $bag = new MessageBag(Message::forSystem(SystemPrompt::build($this->config)));

        foreach ($history->getMessages() as $historyMessage) {
            $bag->add($historyMessage);
        }

        $bag->add(Message::ofUser($message));

        return $bag;
    }
}
