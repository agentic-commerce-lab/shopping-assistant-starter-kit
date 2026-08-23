<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;

/**
 * Keeps the grounded context it was handed. Two of these in one turn is how R32 is asserted: both
 * must have been given the same gateway, trace and renderer instances.
 */
final class CapturingGroundedToolFactory implements GroundedToolFactoryInterface
{
    public ?GroundedToolContext $context = null;

    public function create(GroundedToolContext $context): ?object
    {
        $this->context = $context;

        return new RecordingProbeTool();
    }
}
