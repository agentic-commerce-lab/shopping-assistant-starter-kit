<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;

/** Keeps the context it was handed, so a test can assert what an unprivileged tool actually gets. */
final class RecordingToolFactory implements ToolFactoryInterface
{
    public ?ToolContext $context = null;

    public function create(ToolContext $context): ?object
    {
        $this->context = $context;

        return new RecordingProbeTool();
    }
}
