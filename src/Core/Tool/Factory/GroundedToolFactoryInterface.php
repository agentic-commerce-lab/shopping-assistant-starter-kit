<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * Contributes one **catalogue-facing** tool to a turn. Tag it `swag_assistant.grounded_tool_factory`.
 *
 * The separate name is the warning. A tool built from a {@see GroundedToolContext} can reach the
 * gateway, so it can obtain real prices and stock — and it therefore inherits the duty that makes
 * `VISION.md`'s first non-negotiable true: shopper-facing facts are rendered through
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}, never returned raw to the model.
 *
 * If your tool does not need catalogue data, implement {@see ToolFactoryInterface} instead. It cannot
 * reach the gateway, which means it cannot get this wrong.
 *
 * @api
 */
interface GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object;
}
