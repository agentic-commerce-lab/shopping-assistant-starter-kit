<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * How long a conversation is kept, in days.
 *
 * **Global, not per sales channel.** A scheduled task has no sales-channel context and would
 * otherwise have to iterate every channel to prune one table.
 *
 * There is no "keep forever" value. An unset or nonsensical setting falls back to
 * {@see self::DEFAULT_DAYS} rather than disabling the task, because a merchant who never opens the
 * retention card must still get pruning — this table accumulates what shoppers typed, and the
 * Administration trace view makes it prominent.
 */
final readonly class TraceRetentionSettings
{
    public const DEFAULT_DAYS = 30;

    private const KEY = 'SwagAssistantStarterKit.config.traceRetentionDays';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function retentionDays(): int
    {
        $configured = $this->systemConfig->getInt(self::KEY);

        return $configured > 0 ? $configured : self::DEFAULT_DAYS;
    }
}
