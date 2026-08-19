<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Drives one independent run of one journey, start to finish, through a completely
 * fresh {@see AssistantAgentFactory::create()} call — a new {@see TraceRecorder}, a new
 * {@see FixtureCommerceGateway}, a new {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 * — so this run's trace can never contaminate another's. Within this one run, every
 * turn shares that one bundle and carries the returned prose forward as conversation
 * history, exactly as a real multi-turn shopper session would.
 *
 * Ruling R42: {@see JourneyRunner} does not see only the final turn — every turn's
 * {@see AssistantTurn} is collected and merged via {@see TurnAggregate::of()} before
 * being returned. A single-turn journey's aggregate is trivially that one turn; a
 * multi-turn journey's (today, only `cart_add`) is the union of every turn's cards,
 * prose and unbacked prices, so a safety assertion reading {@see AssistantTurn::$cards}
 * or `$prose` cannot miss something an earlier turn did just because a later turn was
 * clean. The single {@see TraceRecorder} returned alongside it already accumulates every
 * turn's events in one object; it needed no change here — see
 * {@see \Swag\AssistantStarterKit\Eval\Assertion\TraceEvents} for how assertions read
 * ALL of a stage's events rather than only the last.
 *
 * Split out of {@see JourneyRunner} to keep that class's own cyclomatic-complexity total
 * under this project's threshold (mago sums it per class, across every method).
 */
final class JourneyAttempt
{
    public function __construct(
        private readonly LlmSettings $llm,
        private readonly string $catalogFixturePath,
        private readonly ?HttpClientInterface $http = null,
    ) {}

    /**
     * @return array{0: AssistantTurn, 1: TraceRecorder}
     *
     * @throws \Symfony\AI\Agent\Exception\ExceptionInterface propagated from
     *         {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner::run()}'s own
     *         platform call
     */
    public function run(Journey $journey, ?string $archetypePhrase): array
    {
        $gateway = FixtureCommerceGateway::fromFile($this->catalogFixturePath);
        $config = $this->buildConfig($journey);

        // cartAvailable is always true: a real storefront always has a shopper cart, and
        // AssistantConfig::$enableAddToCart (defaulted on) is what actually gates whether
        // add_to_cart is ever constructed, per Ruling R32/R34 in AssistantAgentFactory.
        $bundle = AssistantAgentFactory::create($gateway, $config, true, $this->llm, $this->http);
        $runner = new AssistantRunner($config, $bundle);

        $history = new MessageBag();
        $turns = [];

        foreach ($journey->turns as $turnSpec) {
            $message = 'archetype' === $turnSpec
                ? $this->resolveArchetypePhrase($journey, $archetypePhrase)
                : $turnSpec;

            $turn = $runner->run($message, $history);
            $turns[] = $turn;

            $history->add(Message::ofUser($message));
            $history->add(Message::ofAssistant($turn->prose));
        }

        if ([] === $turns) {
            // Unreachable: Journey::fromFile() already rejects an empty turns list.
            throw new \LogicException(\sprintf('Journey "%s" declares no turns.', $journey->id));
        }

        return [TurnAggregate::of($turns), $bundle->trace];
    }

    private function resolveArchetypePhrase(Journey $journey, ?string $phrase): string
    {
        if (null === $phrase) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" uses the literal "archetype" turn for an archetype with no phrasing.',
                $journey->id,
            ));
        }

        return $phrase;
    }

    private function buildConfig(Journey $journey): AssistantConfig
    {
        /** @var list<string> $blockedProductIds */
        $blockedProductIds = $journey->config['blockedProductIds'] ?? [];

        return new AssistantConfig(scope: new CatalogScope(blockedProductIds: $blockedProductIds));
    }
}
