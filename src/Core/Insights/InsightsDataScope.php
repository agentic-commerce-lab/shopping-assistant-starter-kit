<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * What the nightly judge is handed.
 *
 * The conversation already reaches a model while the shopper is typing, so `FullConversations` is
 * not a new *kind* of disclosure — but it is a second *purpose* for stored data, and where the judge
 * runs on another provider it is a new recipient too. D27 makes that the merchant's call rather than
 * ours, which is why the default is the narrow one and the help text names the condition.
 *
 * An unknown stored value falls back to `Aggregates`: a typo in the database must never widen what
 * leaves the shop.
 */
enum InsightsDataScope: string
{
    case Aggregates = 'aggregates';
    case FullConversations = 'fullConversations';

    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::Aggregates;
    }
}
