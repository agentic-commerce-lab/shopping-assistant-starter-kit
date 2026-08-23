<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * Builds the system prompt for one turn. Decorate or replace the service to change it.
 *
 * The vocabulary arrives as a parameter rather than being fetched here because it is a per-request
 * probe result: {@see \Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory} probes the
 * catalogue's facets once per turn and passes what it found. A provider that went looking for it
 * itself would probe twice.
 *
 * **A replacement inherits the rules, not just the wording.** The shipped prompt carries the
 * injection defence, the absence rule and the escalation clause, and each of those has an eval
 * journey behind it. Replacing the prompt without keeping them will fail those journeys, which is the
 * intended way to find out.
 *
 * @api
 */
interface PromptProviderInterface
{
    public function system(AssistantConfig $config, string $vocabulary = ''): string;
}
