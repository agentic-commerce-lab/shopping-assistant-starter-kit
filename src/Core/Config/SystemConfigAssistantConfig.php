<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * Builds an {@see AssistantConfig} from the merchant's `config.xml` settings for one sales channel.
 *
 * Until this class existed, `config.xml` was a form that changed nothing — including the kill
 * switch, which `ARCHITECTURE.md` calls a security control rather than an operational nicety.
 *
 * **The interesting half is what happens when a key is absent**, because Shopware's typed getters
 * return zero values rather than null:
 *
 * - `getInt()` returns `0`, and 0 is a *valid but catastrophic* value here. `maxToolCallsPerTurn: 0`
 *   means no tool may ever run; `dailyRequestCap: 0` refuses every request. A shop that never opened
 *   the config form must get the documented defaults, not a silently disabled assistant — so ints
 *   are read through {@see self::intOr()}, which distinguishes absent from stored.
 * - `getBool()` returns `false` for an absent key *and* for a stored `false`. For `killSwitch` that
 *   is harmless: both mean off, which is the safe direction. For `enableAddToCart` it is not — the
 *   default is **on**, so an absent key must not read as "the merchant switched it off", and a
 *   stored `false` must not be overridden by the default. Hence the raw `get()`.
 */
final readonly class SystemConfigAssistantConfig
{
    public const PREFIX = 'SwagAssistantStarterKit.config.';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function forSalesChannel(string $salesChannelId): AssistantConfig
    {
        // Named arguments throughout: ruling R17's carve-out for AssistantConfig's parameter-count
        // pragma is conditional on its call sites using them.
        return new AssistantConfig(
            agentVoice: $this->systemConfig->getString(self::PREFIX . 'agentVoice', $salesChannelId),
            scope: $this->scope($salesChannelId),
            enableAddToCart: $this->boolOr('enableAddToCart', true, $salesChannelId),
            maxItemQuantity: $this->intOr('maxItemQuantity', 5, $salesChannelId),
            maxCartValue: $this->floatOr('maxCartValue', 1000.0, $salesChannelId),
            killSwitch: $this->boolOr('killSwitch', false, $salesChannelId),
            dailyRequestCap: $this->intOr('dailyRequestCap', 500, $salesChannelId),
            maxToolCallsPerTurn: $this->intOr('maxToolCallsPerTurn', 5, $salesChannelId),
        );
    }

    private function scope(string $salesChannelId): CatalogScope
    {
        return new CatalogScope(
            excludeCategoryIds: $this->idList('excludedCategories', $salesChannelId),
            blockedProductIds: $this->idList('blockedProducts', $salesChannelId),
            blockedCategoryIds: $this->idList('blockedCategories', $salesChannelId),
        );
    }

    /**
     * One id per line, trimmed, with blanks dropped.
     *
     * All three failure modes here are silent, which is why each is handled explicitly: a single
     * un-split string matches nothing; an id with a trailing `\r` from a Windows textarea matches
     * nothing; and a list of one empty string reports a *configured* blocklist in the trace while
     * blocking nothing at all. D5 makes the blocklist a compliance control, and a compliance
     * control that quietly does nothing is worse than an absent one.
     *
     * @return list<string>
     */
    private function idList(string $key, string $salesChannelId): array
    {
        $raw = $this->systemConfig->getString(self::PREFIX . $key, $salesChannelId);

        $lines = preg_split('/\R/', $raw);

        if ($lines === false) {
            return [];
        }

        $trimmed = array_map(static fn(string $line): string => trim($line), $lines);

        return array_values(array_filter($trimmed, static fn(string $line): bool => $line !== ''));
    }

    private function intOr(string $key, int $default, string $salesChannelId): int
    {
        $value = $this->systemConfig->get(self::PREFIX . $key, $salesChannelId);

        return \is_numeric($value) ? (int) $value : $default;
    }

    private function floatOr(string $key, float $default, string $salesChannelId): float
    {
        $value = $this->systemConfig->get(self::PREFIX . $key, $salesChannelId);

        return \is_numeric($value) ? (float) $value : $default;
    }

    private function boolOr(string $key, bool $default, string $salesChannelId): bool
    {
        $value = $this->systemConfig->get(self::PREFIX . $key, $salesChannelId);

        // Absent means default; a stored value — including false — means what it says.
        return $value === null ? $default : (bool) $value;
    }
}
