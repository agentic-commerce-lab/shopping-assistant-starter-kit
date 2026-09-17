<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * Where a night's conversations come from.
 *
 * An interface rather than the repository directly, because the replay command reads the same
 * shapes out of a JSON export months later — which is how this feature's own design was validated,
 * and how the judge's precision figure is produced without a shop.
 */
interface ConversationTraceSource
{
    /** @return list<ConversationTrace> */
    public function inWindow(InsightWindow $window): array;
}
