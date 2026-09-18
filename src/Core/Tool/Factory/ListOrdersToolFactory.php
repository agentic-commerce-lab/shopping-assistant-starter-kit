<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;

/**
 * Three gates, all of them construction rather than instruction (D6).
 *
 * - `enableOrderHistory` is the merchant's, and it ships off. This is the first capability that
 *   reads something about the shopper rather than about the catalogue.
 * - `loggedIn` is the turn's. **A guest's model never receives this tool in its schema**, so there
 *   is no instruction to disobey and nothing to talk past — a version that built the tool and
 *   checked the shopper inside `__invoke()` would be one prompt away from a different outcome.
 * - `instanceof OrderHistoryReader` is the gateway's own answer to whether it can read orders at
 *   all, the same shape as `browse_categories` against {@see \Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader}.
 *
 * Grounded rather than unprivileged because the tool must register what it fetched with a renderer,
 * and `ToolContext` deliberately carries none. It touches no catalogue, so the gateway, blocklist
 * and variant resolver it also receives go unused — the smaller wrong than a third authority tier
 * with one consumer, and recorded here rather than left to be noticed.
 *
 * **What these three gates do NOT do, stated so nobody has to discover it.** They gate this TOOL,
 * not the capability. `orders()` is a public method on the gateway, and R32 hands that one instance
 * to every grounded factory — so a contributed tool could call `$context->gateway->orders(10)` with
 * `enableOrderHistory` switched off. That is consistent with this project's trust boundary rather
 * than a hole in it: tools are trusted code the merchant installed, and `Guard` protects against the
 * model, not against the tool. The model cannot reach it, because the model only ever sees tools the
 * toolbox constructed. A merchant who wants the switch enforced below the toolbox has to push the
 * check into the gateway, and that is a different design than this one.
 */
final readonly class ListOrdersToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->config->enableOrderHistory || !$context->loggedIn) {
            return null;
        }

        if (!$context->gateway instanceof OrderHistoryReader) {
            return null;
        }

        return new ListOrdersTool($context->gateway, $context->orderRenderer, $context->trace);
    }
}
