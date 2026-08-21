<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily. `ARCHITECTURE.md` calls retention "Not optional" — traces live in the merchant's database
 * and hold what shoppers typed.
 */
class PruneConversationsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'swag_assistant.prune_conversations';
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
