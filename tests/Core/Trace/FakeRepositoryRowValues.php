<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

/**
 * The `mixed`-to-typed narrowing half of {@see FakeRepositoryRows}, split out for the same reason
 * documented on {@see \Swag\AssistantStarterKit\Core\Tool\Guard}: mago's `cyclomatic-complexity`
 * rule is class-scoped and sums every method's own complexity, and once `FakeRepositoryRows`'s two
 * mapping methods started routing every optional field through one of these narrowing calls, their
 * combined total — mapping plus narrowing, in one class — crossed the project's threshold. The
 * narrowing itself did not get any more complex; only which class pays for it changed.
 */
final class FakeRepositoryRowValues
{
    /**
     * Narrows a row value the analyzer can only ever see as `mixed` — it comes from a fixture's own
     * plain array, not from anything typed — to what the entity setter actually accepts. A value of
     * the wrong shape becomes `null` rather than reaching the setter and failing loudly with a
     * `TypeError`: acceptable here because every row this double ever sees was built by
     * {@see DalConversationStore} itself, through the exact setters this file mirrors back, so a
     * genuinely wrong-shaped value would mean the double's caller is already broken in a way its own
     * assertions would catch first.
     */
    public static function nullableString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * `setTranscript()` requires `array<int, mixed>`, a list. `array_values()` is not a defensive
     * guess dressed up as a fix — a transcript is conceptually an ordered sequence of turns, so
     * re-indexing is what "make this a list" genuinely means, and it is a no-op for anything that
     * was already list-shaped.
     *
     * @return array<int, mixed>|null
     */
    public static function nullableList(mixed $value): ?array
    {
        return \is_array($value) ? \array_values($value) : null;
    }

    /**
     * `setPayload()` requires `array<string, mixed>`. Unlike {@see self::nullableList()}, there is
     * no re-indexing operation that turns an arbitrary array into a string-keyed one — so this checks
     * every key explicitly instead of asserting the shape unverified.
     *
     * @return array<string, mixed>|null
     */
    public static function nullableMap(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        foreach (\array_keys($value) as $key) {
            if (!\is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
