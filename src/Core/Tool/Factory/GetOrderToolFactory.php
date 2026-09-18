<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Swag\AssistantStarterKit\Core\Tool\GetOrderTool;

/**
 * The same three gates as {@see ListOrdersToolFactory}, and deliberately the same switch.
 *
 * `enableOrderHistory` covers both tools rather than each getting its own: a merchant who has decided
 * the assistant may show orders has decided it may show what was in them, and a second checkbox would
 * be a distinction nobody asked for that ships as a support question. The two tools are one
 * capability with two entry points.
 *
 * See that factory for why the gates are construction rather than instruction, and for what they do
 * NOT bound.
 */
final readonly class GetOrderToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->config->enableOrderHistory || !$context->loggedIn) {
            return null;
        }

        if (!$context->gateway instanceof OrderHistoryReader) {
            return null;
        }

        return new GetOrderTool($context->gateway, $context->orderRenderer, $context->trace);
    }
}
