<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * Turns the `orders.listed` trace payload into a sentence a failure can carry.
 *
 * Its own class because it is message-building rather than judging, and because folding it into
 * {@see OrdersListedExactly} put that class over mago's complexity threshold — this codebase splits
 * rather than raising the bar.
 */
final class OrderQueryAsked
{
    private function __construct() {}

    /**
     * What the model actually asked the tool for, so a failure explains itself.
     *
     * Without this the message can only say the set was wrong, and the three ways that happens look
     * identical from outside: the model never called the tool, it called it with no filter, or it
     * called it with a filter that matched nothing. Each needs a different fix, and guessing between
     * them costs a paid eval run per guess.
     *
     * @param ?array<string, mixed> $recorded
     */
    public static function describe(?array $recorded): string
    {
        if ($recorded === null) {
            return 'The tool was never called this turn — the model did not reach for it at all.';
        }

        return \sprintf(
            'The model asked for state=%s, withinDays=%s, limit=%s.',
            \is_string($recorded['state'] ?? null) ? $recorded['state'] : 'null',
            \is_int($recorded['withinDays'] ?? null) ? (string) $recorded['withinDays'] : 'null',
            \is_int($recorded['limit'] ?? null) ? (string) $recorded['limit'] : '?',
        );
    }
}
