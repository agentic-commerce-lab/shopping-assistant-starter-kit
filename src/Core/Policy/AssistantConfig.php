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
        public bool $logTraces = false,
        /**
         * The sales channel this config was built for.
         *
         * Not a setting — it is the identity of the scope every other value here belongs to. It
         * lives on the config because `SystemConfigAssistantConfig::forSalesChannel()` already knows
         * it, and because a tool that has the config then has the tenant without widening
         * `ToolContext`, which `docs/extending.md` presents as an extension point.
         *
         * Shop-info retrieval filters on it (spec R12): two channels can have different terms and
         * conditions, and a store query without this filter serves one channel's revocation notice
         * in another.
         */
        public string $salesChannelId = '',
        /**
         * The embedding model, empty when the merchant has not configured one.
         *
         * Empty means shop-info retrieval is entirely off: no tool in the toolbox, no ingestion
         * (spec R13). A starter kit offering a tool that always fails is worse than one offering no
         * tool.
         */
        public string $embeddingModel = '',
    ) {}
}
