<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;

/**
 * The cart tool, whose two conditions are both preserved from the factory this replaced.
 *
 * `enableAddToCart` is the merchant's switch; `cartAvailable` is whether this request even has a
 * shopper cart to add to — false in the probe command and in the eval suite, which run outside a
 * storefront request. Either one absent means the tool is never constructed, so the model never sees
 * it (D6).
 */
final readonly class AddToCartToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->config->enableAddToCart || !$context->cartAvailable) {
            return null;
        }

        return new AddToCartTool(
            $context->gateway,
            $context->blocklist,
            $context->renderer,
            $context->trace,
            $context->config,
        );
    }
}
