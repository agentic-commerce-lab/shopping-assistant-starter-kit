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
 *   means no tool may ever run; `dailyRequestCap: 0` and `requestsPerMinute: 0` refuse every
 *   request. A shop that never opened the config form must get the documented defaults, not a
 *   silently disabled assistant — so ints are read through {@see self::intOr()}, which distinguishes
 *   absent from stored.
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
            requestsPerMinute: $this->intOr('requestsPerMinute', 12, $salesChannelId),
            enableEscalation: $this->boolOr('enableEscalation', true, $salesChannelId),
            escalationUrl: $this->safeUrl('escalationUrl', $salesChannelId),
            escalationMessage: trim($this->systemConfig->getString(
                self::PREFIX . 'escalationMessage',
                $salesChannelId,
            )),
            logTraces: $this->boolOr('logTraces', false, $salesChannelId),
            salesChannelId: $salesChannelId,
            embeddingModel: trim($this->systemConfig->getString(self::PREFIX . 'embeddingModel', $salesChannelId)),
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

    /**
     * A merchant-entered URL, or `''` when it is not one this shop may render.
     *
     * This value ends up in an `href` served to every shopper, so an allowlist rather than a
     * blocklist: an absolute path on this shop, or an explicit http(s) URL. Everything else is
     * dropped, including `javascript:` and `data:` — config access is not permission to run
     * JavaScript in the storefront, and in a real shop those are not the same person.
     *
     * `//host/path` is rejected with them: it *looks* like a path and is a protocol-relative URL
     * that leaves the shop entirely, which is the one hostile case a naive `str_starts_with('/')`
     * check waves through.
     */
    private function safeUrl(string $key, string $salesChannelId): string
    {
        $raw = trim($this->systemConfig->getString(self::PREFIX . $key, $salesChannelId));

        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '//')) {
            return '';
        }

        if (str_starts_with($raw, '/')) {
            return $raw;
        }

        $scheme = strtolower((string) parse_url($raw, \PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], strict: true) ? $raw : '';
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

        if ($value === null) {
            // Absent means the documented default.
            return $default;
        }

        // **Not `(bool)`.** `bin/console system:config:set` stores every value as a string, so a
        // guardrail turned off from the CLI arrives as the string `"false"` — and `(bool) "false"`
        // is `true`. Measured in the real shop: `system_config` held `{"_value":"false"}` for
        // `killSwitch` while the assistant read it as ON.
        //
        // The direction that matters is `enableAddToCart`, whose help text promises the tool "is
        // never constructed" when off: under a plain cast, a merchant disabling it from the CLI
        // would get the tool constructed anyway — a guardrail failing **open** while the admin form
        // shows it as disabled. The admin UI sends real JSON booleans and is unaffected, which is
        // exactly why this stayed invisible.
        //
        // FILTER_VALIDATE_BOOLEAN reads "false"/"0"/"" as false and "true"/"1"/"on"/"yes" as true,
        // and passes real booleans through unchanged.
        return filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }
}
