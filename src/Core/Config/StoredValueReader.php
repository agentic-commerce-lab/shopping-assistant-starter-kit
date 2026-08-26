<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * How one stored `system_config` value becomes a typed one.
 *
 * Split out of {@see SystemConfigAssistantConfig} because the two answer different questions and
 * only one of them is about this plugin. That class knows which settings exist and what each is
 * called; this one knows the three ways Shopware's storage lies to a naive reader, and would say
 * exactly the same thing for any plugin's config form:
 *
 * - **`getInt()` returns `0` for an absent key**, so "never stored" and "stored as zero" are
 *   indistinguishable through the typed getters. Every method here reads the raw `get()` and decides
 *   for itself.
 * - **`getBool()` returns `false` for an absent key *and* for a stored `false`.** Which of those a
 *   caller wants depends entirely on which way the setting's documented default points, so the
 *   default is a required argument rather than a convention.
 * - **`bin/console system:config:set` stores every value as a string.** See {@see self::bool()}.
 */
final readonly class StoredValueReader
{
    public function __construct(
        private SystemConfigService $systemConfig,
        private string $prefix,
    ) {}

    public function string(string $key, string $salesChannelId): string
    {
        return $this->systemConfig->getString($this->prefix . $key, $salesChannelId);
    }

    /**
     * An opt-in limit: absent, blank, zero and negative all mean **unlimited**.
     *
     * Negative is folded in rather than rejected because there is no reading of "-5 items per cart"
     * a merchant could have meant as a restriction, and the alternative — letting it through — makes
     * every `> $limit` comparison downstream block unconditionally.
     */
    public function limit(string $key, string $salesChannelId): int
    {
        return max(0, $this->int($key, 0, $salesChannelId));
    }

    public function floatLimit(string $key, string $salesChannelId): float
    {
        return max(0.0, $this->float($key, 0.0, $salesChannelId));
    }

    /**
     * For a limit that may not be switched off.
     *
     * Absent, zero and negative all read as `$default`; a typed positive number is honoured exactly,
     * high or low. The floor never rewrites a number the merchant chose — a limit quietly raised is
     * the config bridge overruling the form.
     */
    public function positiveInt(string $key, int $default, string $salesChannelId): int
    {
        $value = $this->int($key, $default, $salesChannelId);

        return $value > 0 ? $value : $default;
    }

    public function int(string $key, int $default, string $salesChannelId): int
    {
        $value = $this->systemConfig->get($this->prefix . $key, $salesChannelId);

        return \is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default, string $salesChannelId): float
    {
        $value = $this->systemConfig->get($this->prefix . $key, $salesChannelId);

        return \is_numeric($value) ? (float) $value : $default;
    }

    /**
     * **Not `(bool)`.**
     *
     * `bin/console system:config:set` stores every value as a string, so a setting turned off from
     * the CLI arrives as the string `"false"` — and `(bool) "false"` is `true`. Measured in the
     * running shop: `system_config` held `{"_value":"false"}` for the assistant's off switch while
     * the assistant read it as ON.
     *
     * Both directions this is used in fail dangerously under a plain cast. `enableAddToCart`'s help
     * text promises the tool "is never constructed" when off, so a merchant disabling it from the
     * CLI would get the tool constructed anyway — a guardrail failing **open** while the admin form
     * shows it disabled. `assistantEnabled` is worse: the same cast reads a stopped assistant as
     * enabled and serves it to shoppers. The admin UI sends real JSON booleans and is unaffected,
     * which is exactly why this stayed invisible.
     *
     * `FILTER_VALIDATE_BOOLEAN` reads "false"/"0"/"" as false and "true"/"1"/"on"/"yes" as true, and
     * passes real booleans through unchanged.
     */
    public function bool(string $key, bool $default, string $salesChannelId): bool
    {
        $value = $this->systemConfig->get($this->prefix . $key, $salesChannelId);

        if ($value === null) {
            // Absent means the documented default, which is the whole reason this takes one.
            return $default;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }
}
