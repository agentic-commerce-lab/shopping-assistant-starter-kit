<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * The shipped prompt, behind the interface so it can be decorated.
 *
 * Thin on purpose: {@see SystemPrompt} keeps the rules and the reasoning behind each one, and this
 * class exists so that a merchant or partner replacing the prompt replaces a *service* rather than
 * editing a constant in this plugin.
 */
final readonly class SystemPromptProvider implements PromptProviderInterface
{
    public function system(AssistantConfig $config, string $vocabulary = ''): string
    {
        return SystemPrompt::build($config, $vocabulary);
    }
}
