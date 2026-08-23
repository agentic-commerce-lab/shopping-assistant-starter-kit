<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * A third party's tool, as far as the toolbox is concerned.
 *
 * A named class rather than an anonymous one because Symfony AI reads `#[AsTool]` off a class; an
 * anonymous class carrying the attribute is not something its metadata reader can be relied on to
 * find.
 */
#[AsTool(name: 'recording_probe', description: 'A contributed tool used by the test suite.')]
final class RecordingProbeTool
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
