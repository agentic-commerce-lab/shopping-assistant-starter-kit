<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;

/**
 * Ruling R42: a multi-turn journey's assertions must see every turn's cards, prose and
 * unbacked prices — not only the last turn's, which is all a single {@see AssistantTurn}
 * naturally carries, since {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::render()}
 * and {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::unbackedPricesInProse()}
 * both overwrite their own field on every call. This merges every turn's
 * {@see AssistantTurn} into one, so the {@see Assertion} interface — whose exact three
 * methods are otherwise unchanged — still receives a single {@see AssistantTurn}, but
 * one that represents "everything that happened this run", not "only the last thing".
 *
 * `outcome` is taken from the LAST turn only, deliberately: it is the run's definitive
 * final state (e.g. `cart_added`), not something that should be unioned across turns.
 * `prose` is every turn's prose joined by a newline, so a substring search (as
 * {@see Assertion\BlocklistRespected} does for a blocked product's name) still finds a
 * mention from any turn. `cards` are deduplicated by id, a LATER turn's card superseding
 * an earlier one for the same id — the same "later registration wins" rule
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::registerRetrieved()} and
 * {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} already use. Trace-stage
 * based checks (e.g. `validate`) are NOT aggregated here — see {@see Assertion\TraceEvents}
 * for those, since they read the trace directly rather than this merged turn.
 */
final class TurnAggregate
{
    /** @param list<AssistantTurn> $turns */
    public static function of(array $turns): AssistantTurn
    {
        if ([] === $turns) {
            throw new \LogicException('Cannot aggregate an empty list of turns.');
        }

        /** @var array<string, \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard> $cardsById */
        $cardsById = [];
        $proseParts = [];
        $unbackedPrices = [];
        $lastTurn = null;

        foreach ($turns as $turn) {
            $lastTurn = $turn;
            $proseParts[] = $turn->prose;

            foreach ($turn->cards as $card) {
                $cardsById[$card->id] = $card;
            }

            foreach ($turn->unbackedPrices as $price) {
                $unbackedPrices[] = $price;
            }
        }

        if (null === $lastTurn) {
            // Unreachable: the empty-list check above already threw.
            throw new \LogicException('Cannot aggregate an empty list of turns.');
        }

        return new AssistantTurn(
            prose: implode("\n", $proseParts),
            cards: array_values($cardsById),
            outcome: $lastTurn->outcome,
            unbackedPrices: array_values(array_unique($unbackedPrices)),
        );
    }
}
