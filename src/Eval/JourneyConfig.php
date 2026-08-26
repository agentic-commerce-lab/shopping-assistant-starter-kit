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
 *
 * `embeddingModel` is that decision for shop information: naming a model in a journey's `config` is
 * what puts `search_shop_info` in that journey's toolbox, and nowhere else.
 */
final class JourneyConfig
{
    public static function of(Journey $journey): AssistantConfig
    {
        $unknown = array_diff(array_keys($journey->config), [
            'blockedProductIds',
            'enableEscalation',
            'escalationUrl',
            'embeddingModel',
        ]);

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
            // Spec R12: the fixture writes its passages for one channel and the tool filters on the
            // channel in this config, so the two must be the same one or the journey silently
            // retrieves nothing — precisely the "tests nothing" failure this class exists to prevent.
            salesChannelId: ShopInfoFixture::SALES_CHANNEL_ID,
            // Empty unless a journey asks for shop information, which keeps the tool out of every
            // other journey's toolbox (spec R13). A new tool changes what the model can choose, so
            // the fifteen existing journeys must not silently acquire one.
            embeddingModel: (string) ($journey->config['embeddingModel'] ?? ''),
        );
    }
}
