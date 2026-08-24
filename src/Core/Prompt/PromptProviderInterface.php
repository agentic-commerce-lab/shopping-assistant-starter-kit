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
    /**
     * `$viewing` is the already-rendered {@see ViewingContext} line naming the product the shopper
     * has open, or an empty string. It arrives as a parameter for the same reason `$vocabulary`
     * does: it is per-request state this turn resolved, and a provider that went looking for it
     * itself would have to resolve a product id it has no catalogue scope to check it against.
     *
     * **A provider that ignores it loses page context silently** — nothing fails, the assistant is
     * simply no longer told what the shopper is looking at.
     */
    public function system(AssistantConfig $config, string $vocabulary = '', string $viewing = ''): string;
}
