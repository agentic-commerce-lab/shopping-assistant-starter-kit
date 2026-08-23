<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;

/**
 * The retrieval tool. Always available: without it there is no assistant, only a chat window.
 */
final readonly class SearchProductsToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        return new SearchProductsTool(
            $context->gateway,
            $context->facetProbe,
            $context->queryBuilder,
            $context->variantResolver,
            $context->blocklist,
            $context->renderer,
            $context->trace,
            $context->config,
        );
    }
}
