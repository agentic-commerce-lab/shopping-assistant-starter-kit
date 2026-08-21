<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * The contact block that goes out beside an escalated reply.
 *
 * **Built from the outcome and the merchant's configuration, never from the model's prose.** That is
 * the same rule as prices and stock (D3): the model supplies words, the shop supplies facts, and a
 * contact URL is a fact. It is also why the URL is not in the model's context at all — a link it
 * could not see is a link it cannot get wrong.
 *
 * Null is the normal answer. It means "this turn was not an escalation", "no destination is
 * configured", or "the merchant switched escalation off since this turn was stored" — and all three
 * must render nothing rather than an empty block: an escalation notice with no link is the promise
 * this class exists to stop making.
 */
final readonly class HandoffPayload
{
    private const OUTCOME_ESCALATED = 'escalated';

    /**
     * @return array{message: string, url: string}|null
     */
    public function of(string $outcome, AssistantConfig $config): ?array
    {
        // `enableEscalation` is checked even though a live escalated turn is impossible without it:
        // history re-hydrates stored turns through here, and a transcript written before the
        // merchant switched escalation off must not keep offering the handoff afterwards.
        if (!$config->enableEscalation || $outcome !== self::OUTCOME_ESCALATED) {
            return null;
        }

        if ($config->escalationUrl === '') {
            return null;
        }

        return [
            // Empty on purpose when unset: the fallback is a snippet, and snippets are translated in
            // the storefront. An English default here would reach a German shop in English.
            'message' => $config->escalationMessage,
            'url' => $config->escalationUrl,
        ];
    }
}
