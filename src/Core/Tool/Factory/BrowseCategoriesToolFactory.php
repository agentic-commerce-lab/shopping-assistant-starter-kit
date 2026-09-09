<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;

/**
 * One gate, and it is a capability of the gateway rather than a merchant setting.
 *
 * `CategoryTreeReader` is optional — `docs/extending.md` lists it among the interfaces a contributed
 * gateway may not implement, and says what is lost: "a search that finds nothing offers no
 * orientation". A gateway that cannot describe its tree cannot answer an assortment question either,
 * so the tool is never constructed and the model never sees it (D6). That is also why its guidance
 * lives in its own description rather than in the system prompt: the prompt cannot tell whether this
 * turn has the tool, and an instruction to call one that is absent is worse than none.
 *
 * No merchant switch. It reads the shop's own public category tree — the same names the storefront
 * navigation shows — writes nothing, and needs no configured destination, so a toggle would be a
 * setting with no failure mode to guard. Same argument as `go_to_checkout`.
 */
final readonly class BrowseCategoriesToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->gateway instanceof CategoryTreeReader) {
            return null;
        }

        return new BrowseCategoriesTool($context->gateway, $context->trace, $context->config->scope);
    }
}
