<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\EscalateTool;

/**
 * The shipped proof that the unprivileged tier is usable, and not a courtesy interface.
 *
 * Escalation needs somewhere to record what it did and the merchant's settings, and nothing from the
 * catalogue at all — so it is the one shipped tool that belongs on the default tier. A contributed
 * store locator or FAQ lookup has exactly this shape.
 */
final readonly class EscalateToolFactory implements ToolFactoryInterface
{
    public function create(ToolContext $context): ?object
    {
        // Null rather than a disabled tool: never constructed means never in the schema the model
        // sees, which is what keeps capability control out of the prompt (D6).
        if (!$context->config->enableEscalation) {
            return null;
        }

        return new EscalateTool($context->trace, $context->config);
    }
}
