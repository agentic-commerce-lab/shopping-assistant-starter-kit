<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;

/**
 * Builds an {@see AssistantConfig} from the merchant's `config.xml` settings for one sales channel.
 *
 * Until this class existed, `config.xml` was a form that changed nothing — including the off switch,
 * which `ARCHITECTURE.md` calls a security control rather than an operational nicety.
 *
 * **What each setting is called and what its default is** lives here. **How a stored value is
 * coerced** — including every way Shopware's storage misleads a naive reader — lives in
 * {@see StoredValueReader}, because that half would read identically for any plugin's config form
 * and reading it here made this class a list of settings interrupted by a treatise on
 * `FILTER_VALIDATE_BOOLEAN`.
 *
 * ## Zero means unlimited
 *
 * The four limits ship at `0`, and zero means *no limit*. It used to mean the opposite for the two
 * rate windows — `dailyRequestCap: 0` refused every request — which made zero the most destructive
 * value a merchant could type into a numeric field, in a form that already carries a deliberate off
 * switch. It also left *"unlimited"* with no way to express itself at all.
 *
 * `maxToolCallsPerTurn` is the exception and takes no zero: it bounds a model that has started
 * looping rather than a merchant's budget, so it is read through `positiveInt()` and floored.
 *
 * ## `assistantEnabled` replaced `killSwitch`
 *
 * Same control, inverted, because a toggle whose blue ON position stopped the product read as
 * backwards to everyone who had not read the source.
 * {@see \Swag\AssistantStarterKit\Migration\Migration1788048000InvertKillSwitch} carries stored
 * values across; without it every shop that had deliberately switched the assistant **off** would
 * have come back **on** at update time, the new key being absent and defaulting to enabled. The
 * trace reason code stays `kill_switch`: a recorded wire value is an interface, and renaming it
 * would only make old traces harder to read.
 */
final readonly class SystemConfigAssistantConfig
{
    public const PREFIX = 'SwagAssistantStarterKit.config.';

    /**
     * Matches `config.xml`'s `defaultValue` and {@see AssistantConfig}'s constructor default. Named
     * because the floor needs the same number the form shows, and three copies of `20` is how a
     * form and a runtime drift apart.
     */
    private const DEFAULT_TOOL_CALLS_PER_TURN = 20;

    private const DEFAULT_REQUESTS_PER_MINUTE = 60;

    private StoredValueReader $stored;

    /**
     * `$shopInfo` decides whether this shop can run shop-information retrieval at all. Defaulted to
     * "assume it can" so every existing construction — the eval harness, the endpoint tests — keeps
     * meaning what it meant; the container passes the real one.
     */
    public function __construct(
        SystemConfigService $systemConfig,
        private readonly ?ShopInfoAvailability $shopInfo = null,
    ) {
        $this->stored = new StoredValueReader($systemConfig, self::PREFIX);
    }

    /**
     * `$storefrontLocale` is the only argument here that is not a stored setting, and it is a
     * parameter rather than a lookup because it cannot be one: one sales channel serves several
     * domains, and Shopware's storefront picks the snippets around this widget from the DOMAIN's
     * locale. The channel row would answer a different question — and would answer it wrongly for
     * every shop whose English and German storefronts share a channel.
     *
     * Null for any caller the storefront's `RequestTransformer` never touched: the console probe,
     * the eval harness, a direct API call.
     */
    public function forSalesChannel(string $salesChannelId, ?string $storefrontLocale = null): AssistantConfig
    {
        // Named arguments throughout: ruling R17's carve-out for AssistantConfig's parameter-count
        // pragma is conditional on its call sites using them.
        return new AssistantConfig(
            agentVoice: $this->stored->string('agentVoice', $salesChannelId),
            scope: $this->scope($salesChannelId),
            enableAddToCart: $this->stored->bool('enableAddToCart', true, $salesChannelId),
            maxItemQuantity: $this->stored->limit('maxItemQuantity', $salesChannelId),
            maxCartValue: $this->stored->floatLimit('maxCartValue', $salesChannelId),
            assistantEnabled: $this->stored->bool('assistantEnabled', true, $salesChannelId),
            dailyRequestCap: $this->stored->limit('dailyRequestCap', $salesChannelId),
            maxToolCallsPerTurn: $this->stored->positiveInt(
                'maxToolCallsPerTurn',
                self::DEFAULT_TOOL_CALLS_PER_TURN,
                $salesChannelId,
            ),
            requestsPerMinute: $this->stored->int(
                'requestsPerMinute',
                self::DEFAULT_REQUESTS_PER_MINUTE,
                $salesChannelId,
            ),
            enableEscalation: $this->stored->bool('enableEscalation', true, $salesChannelId),
            escalationUrl: $this->safeUrl('escalationUrl', $salesChannelId),
            escalationMessage: trim($this->stored->string('escalationMessage', $salesChannelId)),
            enableMatchReasons: $this->stored->bool('enableMatchReasons', false, $salesChannelId),
            enableCompareProducts: $this->stored->bool('enableCompareProducts', false, $salesChannelId),
            logTraces: $this->stored->bool('logTraces', true, $salesChannelId),
            salesChannelId: $salesChannelId,
            // Read as empty on a shop that cannot run the feature — see self::shopInfoUsable().
            embeddingModel: $this->embeddingModel($salesChannelId),
            // Through StoredValueReader::bool(), never a cast: `system:config:set ... false` stores
            // the string "false", and `(bool) "false"` is true. The kill switch was measured failing
            // exactly that way, and this one would spend embedding money rather than open a
            // guardrail. That reader runs filter_var(FILTER_VALIDATE_BOOLEAN), which reads it right.
            autoIndexShopPages: $this->shopInfoUsable()
            && $this->stored->bool('autoIndexShopPages', false, $salesChannelId),
            // Through ReplyLanguage rather than stored as given: its closed list is what keeps a
            // string out of the system prompt that the merchant's database could otherwise choose.
            defaultReplyLanguage: ReplyLanguage::of($storefrontLocale),
        );
    }

    /**
     * The merchant's model name, or an empty string on a shop that cannot use it.
     *
     * **The stored value is left alone.** This reads it as off rather than clearing it, so moving a
     * shop onto a MariaDB brings shop information straight back without anybody retyping a model
     * name — and so the settings screen can still show what was chosen while explaining why it is
     * inactive.
     */
    private function embeddingModel(string $salesChannelId): string
    {
        if (!$this->shopInfoUsable()) {
            return '';
        }

        return trim($this->stored->string('embeddingModel', $salesChannelId));
    }

    /**
     * **Why an unmet requirement is "off" rather than an error.** Measured on the staging shop on
     * 2026-08-31: `embeddingModel` was set, `symfony/ai-store` was not installed, and every shopper
     * message came back a 500 from a feature nobody was using. `embeddingModel: ''` already means
     * switched off everywhere in this plugin (spec R13) — no tool constructed, nothing in the
     * model's schema, ingestion refused at the CLI — so this reuses a path that is already tested
     * instead of inventing a second kind of unavailable.
     *
     * Null availability means "not wired", which only happens in tests and in the eval harness;
     * those shops are not running shop information off a real database either way.
     */
    private function shopInfoUsable(): bool
    {
        return $this->shopInfo === null || $this->shopInfo->isAvailable();
    }

    private function scope(string $salesChannelId): CatalogScope
    {
        return new CatalogScope(
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
        $raw = $this->stored->string($key, $salesChannelId);

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
        $raw = trim($this->stored->string($key, $salesChannelId));

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
}
