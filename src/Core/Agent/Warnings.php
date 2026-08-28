<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

/**
 * The ways a turn's prose can contradict what the server actually rendered, bundled into one value
 * object because AssistantTurn's own constructor sits at mago's excessive-parameter-list threshold —
 * a fourth flat list would breach it. A new claim type is added here, never as a new AssistantTurn
 * parameter.
 */
final readonly class Warnings
{
    /**
     * @param list<string> $unbackedPrices
     * @param list<string> $unbackedAvailabilityClaims
     * @param list<string> $unbackedPropertyClaims
     */
    public function __construct(
        public array $unbackedPrices = [],
        public array $unbackedAvailabilityClaims = [],
        public array $unbackedPropertyClaims = [],
    ) {}
}
