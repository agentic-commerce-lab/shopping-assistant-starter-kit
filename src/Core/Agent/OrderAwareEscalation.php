<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Tells the model not to escalate order questions on a turn that can answer them.
 *
 * {@see EscalateTool}'s description sends order status to a human, and on a turn with an order tool
 * the model obeyed it: `list_orders`, then `escalate`, on staging 2026-09-24. The description has to
 * depend on the toolbox, and `#[AsTool]` is static — so, as with {@see ExplicitEmptyToolSchema}, the
 * one place to say it is where {@see Tool} metadata is made.
 *
 * **Matched by class, not by the name `escalate`.** A contributed tool that happens to share the name
 * wrote its own description and keeps it; only the shipped tool's words are the shipped tool's to
 * change.
 *
 * `$orderToolsOffered` is decided by {@see AssistantAgentFactory} from what it constructed — see
 * {@see \Swag\AssistantStarterKit\Core\Tool\OrderToolsOffered}. False is every guest, every gateway
 * that cannot read orders and every shop without the switch, and then this yields the attribute's own
 * description untouched.
 */
final readonly class OrderAwareEscalation implements ToolFactoryInterface
{
    public function __construct(
        private bool $orderToolsOffered,
        private ToolFactoryInterface $inner = new ReflectionToolFactory(),
    ) {}

    public function getTool(object|string $reference): iterable
    {
        foreach ($this->inner->getTool($reference) as $tool) {
            yield $this->orderToolsOffered && $tool->getReference()->getClass() === EscalateTool::class
                ? new Tool(
                    $tool->getReference(),
                    $tool->getName(),
                    EscalateTool::DESCRIPTION_WITH_ORDER_TOOLS,
                    $tool->getParameters(),
                    $tool->getMetadata(),
                )
                : $tool;
        }
    }
}
