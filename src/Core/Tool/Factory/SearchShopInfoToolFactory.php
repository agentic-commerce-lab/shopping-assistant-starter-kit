<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;

/**
 * Contributes {@see SearchShopInfoTool}, on the unprivileged tier.
 *
 * The unprivileged tier is right because shop information is not a catalogue fact: this tool needs
 * somewhere to record what it did and the merchant's settings, and nothing from the gateway.
 * {@see EscalateToolFactory} is the precedent, and {@see ToolContext} explains why that tier exists at
 * all — a tool that could reach a `ProductCard` could hand the model a raw price.
 *
 * The embedder is built per turn from the context's config, because the provider, key and model are
 * all per-channel settings and the vectors in the store are only comparable within one of them.
 */
final readonly class SearchShopInfoToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private EmbedderFactory $embedders,
        private PassageStore $store,
    ) {}

    public function create(ToolContext $context): ?object
    {
        // Null, not a disabled tool: never constructed means never in the schema the model sees,
        // which is what keeps capability control out of the prompt (D6) — and it is spec R13's off
        // switch. A starter kit offering a tool that always fails is worse than one offering no tool.
        if ($context->config->embeddingModel === '') {
            return null;
        }

        return new SearchShopInfoTool(
            $this->embedders->forSalesChannel($context->config->salesChannelId, $context->config->embeddingModel),
            $this->store,
            $context->trace,
            $context->config,
        );
    }
}
