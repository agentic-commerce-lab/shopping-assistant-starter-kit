<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

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

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->stored = new StoredValueReader($systemConfig, self::PREFIX);
    }

    public function forSalesChannel(string $salesChannelId): AssistantConfig
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
            logTraces: $this->stored->bool('logTraces', true, $salesChannelId),
        );
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
