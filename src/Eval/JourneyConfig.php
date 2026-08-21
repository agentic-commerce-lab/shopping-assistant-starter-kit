<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * Turns a journey's `config` block into the {@see AssistantConfig} its run executes against.
 *
 * **Unknown keys are refused, not ignored.** This used to be four lines inside
 * {@see JourneyAttempt}: it read `blockedProductIds` and silently dropped everything else, so a
 * journey could declare a shop it was not actually run against and still report green. A journey is a
 * claim about a shop, and the cheapest way to make that claim false is a typo nothing reads — the
 * same reasoning that makes an empty blocklist an error rather than a convenience (D5).
 *
 * Only the keys journeys actually need are here. Adding a setting to `AssistantConfig` does not
 * automatically make it journey-configurable, and that is deliberate: each one is a decision about
 * what an eval is allowed to vary.
 */
final class JourneyConfig
{
    public static function of(Journey $journey): AssistantConfig
    {
        $unknown = array_diff(
            array_keys($journey->config),
            [
                'blockedProductIds',
                'enableEscalation',
                'escalationUrl',
            ],
        );

        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" configures %s, which no journey setting reads. '
                . 'A key nothing reads makes the journey a claim about a shop it was not run against.',
                $journey->id,
                implode(', ', array_map(static fn(string $key): string => '"' . $key . '"', $unknown)),
            ));
        }

        /** @var list<string> $blockedProductIds */
        $blockedProductIds = $journey->config['blockedProductIds'] ?? [];

        return new AssistantConfig(
            scope: new CatalogScope(blockedProductIds: $blockedProductIds),
            enableEscalation: (bool) ($journey->config['enableEscalation'] ?? true),
            escalationUrl: (string) ($journey->config['escalationUrl'] ?? ''),
        );
    }
}
