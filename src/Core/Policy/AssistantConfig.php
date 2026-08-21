<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

final readonly class AssistantConfig
{
    // @mago-expect lint:excessive-parameter-list
    // Each field is an independently merchant-tunable setting (voice, scope, cart limits,
    // kill switch, rate caps); grouping them into a sub-object would just move the same
    // flat settings behind another layer of indirection without reducing what a caller
    // needs to reason about.
    public function __construct(
        public string $agentVoice = '',
        public CatalogScope $scope = new CatalogScope(),
        public bool $enableAddToCart = true,
        public int $maxItemQuantity = 5,
        public float $maxCartValue = 1000.0,
        public bool $killSwitch = false,
        public int $dailyRequestCap = 500,
        public int $maxToolCallsPerTurn = 5,
        public int $requestsPerMinute = 12,
        public bool $enableEscalation = true,
        public string $escalationUrl = '',
        public string $escalationMessage = '',
    ) {}
}
