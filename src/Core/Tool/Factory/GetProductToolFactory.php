<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\GetProductTool;

/**
 * Single-product lookup by id. Always available, for the same reason as retrieval.
 */
final readonly class GetProductToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        return new GetProductTool(
            $context->gateway,
            $context->variantResolver,
            $context->blocklist,
            $context->renderer,
            $context->trace,
            $context->config,
        );
    }
}
