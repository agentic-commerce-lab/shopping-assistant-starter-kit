<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;

/**
 * One gate, and no merchant switch — see {@see GoToCheckoutTool} for why.
 *
 * `cartAvailable` is whether this request has a shopper cart at all: false in the probe command and
 * the eval harness, which run outside a storefront request and have no cart to read. Absent means
 * never constructed, so the model never sees the tool (D6).
 */
final readonly class GoToCheckoutToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->cartAvailable) {
            return null;
        }

        return new GoToCheckoutTool($context->gateway, $context->trace);
    }
}
