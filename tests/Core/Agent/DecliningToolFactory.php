<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;

/** A contributed tool that decides it is not available this turn — the `null` contract. */
final class DecliningToolFactory implements ToolFactoryInterface
{
    public function create(ToolContext $context): ?object
    {
        return null;
    }
}
