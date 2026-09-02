<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * A `SystemConfigService` backed by an array.
 *
 * A subclass with an overridden constructor rather than a mock: the real service needs seven
 * collaborators (a database connection among them), and a seven-mock test double is one nobody
 * audits. Overriding `__construct` without calling the parent is legal here precisely because
 * nothing below reaches the parent's state.
 *
 * The typed getters mirror the real ones' fallbacks exactly — that matters, because the config
 * bridge's behaviour when a key is **absent** is the interesting half: an unset kill switch must
 * read as "off" and not as "unknown".
 */
final class FakeSystemConfigService extends SystemConfigService
{
    /**
     * Config values are scalars, lists, or absent — never arbitrary objects — so the type is stated
     * precisely rather than as `mixed`, which lets the analyzer prove the getters below.
     *
     * Lists are here because `sw-entity-multi-id-select` stores a real JSON array. Every typed
     * getter below still reports an array the way the real service does — `getString()` on one
     * returns `''` — so a reader that has not been taught about lists keeps behaving identically.
     *
     * @param array<string, string|int|float|bool|list<mixed>|null> $values
     */
    public function __construct(
        private readonly array $values = [],
    ) {
        // Deliberately not calling parent::__construct(): the parent's collaborators are a
        // connection, a cache-tag collector and a clock, none of which any override below touches.
    }

    public function get(string $key, ?string $salesChannelId = null): string|int|float|bool|array|null
    {
        return $this->values[$key] ?? null;
    }

    public function getString(string $key, ?string $salesChannelId = null): string
    {
        $value = $this->values[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    public function getInt(string $key, ?string $salesChannelId = null): int
    {
        $value = $this->values[$key] ?? null;

        return \is_numeric($value) ? (int) $value : 0;
    }

    public function getFloat(string $key, ?string $salesChannelId = null): float
    {
        $value = $this->values[$key] ?? null;

        return \is_numeric($value) ? (float) $value : 0.0;
    }

    public function getBool(string $key, ?string $salesChannelId = null): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }
}
