<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\BoundedToolbox;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
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
 * history.
 *
 * **Correction, 2026-08-20 (ruling R84):** that sharing was described here as "exactly as a real
 * multi-turn shopper session would". It is not. `ShopwareChatTurnRunner` builds a **fresh bundle per
 * HTTP request**, so a real session gets a new tool-call budget, trace and renderer per message. The
 * shared bundle survives here because {@see TurnAggregate} and ruling R42's multi-turn assertions
 * depend on one `TraceRecorder` accumulating every turn's events — but the **budget** is now reset
 * per turn via {@see BoundedToolbox::startTurn()}, because leaving it shared made `cart_add` fail 6
 * of 6 runs on a limit production would have refreshed.
 *
 * One discrepancy is knowingly left: the shared {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 * means turn 2 here still knows turn 1's retrieved ids, where production would not. That makes
 * `no_invented_product` marginally more permissive in the harness than in the shop. It matters little
 * in practice — since ruling R47 the model no longer emits ids in prose at all — and changing it
 * changes what a safety assertion sees, which is its own decision rather than a side effect of this
 * one.
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
 * **Page context is resolved here the way `ShopwareChatTurnRunner` resolves it**, through
 * `gateway->product($id, $config->scope)`, and the resulting `page.context` event is recorded
 * before the first turn. A journey may therefore block its own page product and assert that page
 * context granted nothing — the trust model checked by an eval rather than only by a unit test.
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
        // The shop document a `shop_info_*` journey retrieves from, or null when none is configured.
        // Null makes such a journey FAIL rather than pass vacuously — see shopInfoFactories(). A
        // journey that goes green because it tested nothing is the one outcome worse than a red one.
        private readonly ?string $shopInfoFixturePath = null,
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
        $config = JourneyConfig::of($journey);

        // cartAvailable is always true: a real storefront always has a shopper cart, and
        // AssistantConfig::$enableAddToCart (defaulted on) is what actually gates whether
        // add_to_cart is ever constructed, per Ruling R32/R34 in AssistantAgentFactory.
        // Resolved through the same gateway and the same CatalogScope a real turn uses, because that
        // is what makes a journey a claim about the shipped pipeline: ShopwareChatTurnRunner does
        // exactly this, and a harness that skipped it could pre-ground a card production would have
        // refused. A blocked page product therefore resolves to null here too.
        $viewing = $journey->page->productId === null
            ? null
            : $gateway->product($journey->page->productId, $config->scope);

        $bundle = AssistantAgentFactory::withCoreToolsOnly($this->http)
            ->withAdditionalFactories($this->shopInfoFactories($config->embeddingModel), [])
            ->create(
                $gateway,
                $config,
                true,
                $this->llm,
                viewing: $viewing,
                browsingCategoryId: $journey->page->categoryId,
            );

        // Recorded before any turn runs, as production records it, so a journey can assert on it.
        $bundle->trace->record('page.context', [
            'reported' => $journey->page->productId !== null,
            'resolved' => $viewing?->id,
            'category' => $journey->page->categoryId,
        ]);

        $runner = new AssistantRunner($config, $bundle);

        $history = new MessageBag();
        $turns = [];

        foreach ($journey->turns as $turnSpec) {
            $message = 'archetype' === $turnSpec
                ? $this->resolveArchetypePhrase($journey, $archetypePhrase)
                : $turnSpec;

            // Each turn gets its own tool-call budget, because that is what production does:
            // ShopwareChatTurnRunner builds a fresh bundle per HTTP request. Sharing the counter made
            // the bound per CONVERSATION here, and `cart_add` failed 6 of 6 runs on a budget the
            // endpoint would have refreshed — a harness artifact reported as a product defect
            // (ruling R84).
            if ($bundle->toolbox instanceof BoundedToolbox) {
                $bundle->toolbox->startTurn();
            }

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

    /**
     * The shop-info tool, for the journeys that ask for it, and nothing for the rest.
     *
     * **An unavailable fixture throws instead of returning nothing.** Returning `[]` would leave the
     * journey running without the tool it is about: the model would decline every question for want
     * of a tool, `shop_info_not_in_documents` would go green for entirely the wrong reason, and the
     * suite would report that the assistant correctly refuses to invent when in fact it never
     * retrieved anything to be tempted by. The plan named that exact failure mode as the one to
     * avoid, so this fails loudly and names what is missing.
     *
     * @return list<ToolFactoryInterface>
     */
    private function shopInfoFactories(string $embeddingModel): array
    {
        if ($embeddingModel === '') {
            return [];
        }

        if ($this->shopInfoFixturePath === null) {
            throw new \RuntimeException(
                'This journey configures an embeddingModel, so it needs a shop information document '
                . 'to retrieve from, and none was given to JourneyAttempt. Without one the journey '
                . 'would pass by testing nothing.',
            );
        }

        return [new ShopInfoFixtureToolFactory(ShopInfoFixture::indexed(
            $this->shopInfoFixturePath,
            $this->llm,
            $embeddingModel,
            $this->http,
        ))];
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
}
