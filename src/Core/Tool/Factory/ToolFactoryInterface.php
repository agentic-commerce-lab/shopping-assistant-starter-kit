<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * Contributes one tool to a turn. Tag the implementation `swag_assistant.tool_factory`.
 *
 * **A factory rather than a service**, because tools are built per request around one shared gateway,
 * trace and renderer (R32) — a stateless tagged service could not hold them, and one that held them
 * across requests would leak one shopper's retrieved set into another's turn, which is the single
 * worst thing this pipeline could do.
 *
 * Returning `null` means "not this turn": that is how `enableAddToCart` and `enableEscalation` are
 * enforced, and it is the mechanism a contributed tool should use for its own switch. Capability
 * control is toolbox construction, never a prompt instruction (D6) — a tool that is never constructed
 * never appears in the schema the model sees, so it cannot be talked into using one.
 *
 * The return type is `object` because Symfony AI tools are plain classes carrying `#[AsTool]`, with no
 * common interface. That is the framework's contract, and ADR 0001 accepted it knowingly.
 *
 * @api
 */
interface ToolFactoryInterface
{
    public function create(ToolContext $context): ?object;
}
