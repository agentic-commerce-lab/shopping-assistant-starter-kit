<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The result of one {@see AssistantRunner::run()} call: the shopper-facing
 * prose, the cards {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 * actually rendered (never anything the model said, unsubstituted), the
 * machine-readable outcome for logging/analytics, and the two ways the prose can
 * contradict the cards: a currency figure no rendered card backs, and an
 * availability claim every rendered card contradicts.
 */
final readonly class AssistantTurn
{
    /**
     * @param list<ProductCard> $cards
     * @param list<string>      $unbackedPrices
     * @param list<string>      $unbackedAvailabilityClaims phrases asserting availability that the
     *        rendered cards contradict — see
     *        {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::unbackedAvailabilityInProse()}
     */
    public function __construct(
        public string $prose,
        public array $cards,
        public string $outcome,
        public array $unbackedPrices = [],
        public array $unbackedAvailabilityClaims = [],
    ) {}
}
