<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/** The tool a declining factory would have built, if it ever built one. */
#[AsTool(name: 'declining_probe', description: 'A contributed tool that is never constructed.')]
final class DecliningProbeTool
{
    public function __invoke(): string
    {
        return 'never';
    }
}
