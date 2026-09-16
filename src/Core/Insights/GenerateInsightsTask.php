<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily, following {@see \Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTask}.
 *
 * Daily rather than hourly because the window is "since the last run" either way, and a merchant
 * reading findings over breakfast does not benefit from four partial nights. It also keeps the
 * judge's cost predictable, which matters when the merchant pays it.
 *
 * Like the pruner, this needs a worker — or the Administration open. A shop with neither writes no
 * runs at all, and the dashboard's empty state has to say so rather than implying a quiet night.
 */
class GenerateInsightsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'swag_assistant.generate_insights';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
