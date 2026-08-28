<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\CompareProductsTool;

final readonly class CompareProductsToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->config->enableCompareProducts) {
            return null;
        }

        return new CompareProductsTool(
            $context->gateway,
            $context->blocklist,
            $context->renderer,
            $context->trace,
            $context->config,
        );
    }
}
