<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Normalises the `options` shapes a live model actually emits into the one
 * {@see VariantSelectionGuard} builds {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection}
 * objects from.
 *
 * Its own class rather than another branch inside `VariantSelectionGuard` for the
 * same reason that guard was split out of {@see Guard}: mago sums cyclomatic
 * complexity per class, and the standing constraint is to split rather than
 * suppress.
 *
 * **Why this exists at all.** The tool schema documents one shape — a list of
 * `{"option": …, "group": …}` objects — and `anthropic/claude-sonnet-5` does not
 * send it. Measured through `swag:assistant:probe --ask` on 2026-08-20, every run
 * of a variant question sent `[["Colour","Black"],["Size","M"]]`: a list of
 * `[group, option]` pairs. Each was rejected, which cost one of the turn's five
 * tool calls, and the retry passed the group NAMES as option values, so both
 * filters were dropped and the whole family came back — the arithmetic behind the
 * `tool_limit_exceeded` turns that both 2026-08-20 handoffs recorded as
 * phrasing-sensitivity.
 *
 * **Why this is not the coercion `Guard` refuses.** `Guard` rejects rather than
 * coerces because a bound quietly relaxed is a silent injection success. These
 * shapes are not bounds: `[["Colour","Black"]]` and
 * `[{"option":"Black","group":"Colour"}]` carry identical information, and
 * accepting the first grants nothing the second does not. Every bound —
 * {@see Guard::boundedArray()}'s entry count and {@see Guard::boundedString()}'s
 * lengths — still applies afterwards, unchanged. What stays a rejection is input
 * that is ambiguous (a tuple that is not a pair — see {@see VariantSelectionPair})
 * or carries no option value at all.
 */
final class VariantSelectionShape
{
    private function __construct() {}

    /**
     * @param array-key $key   the entry's own key, which carries the group name in the
     *                         `{"Colour":"Black"}` map shape and is an offset otherwise
     * @param mixed     $entry one raw entry of the `options` argument
     *
     * @return array{option: string, group: ?string}|null null when the entry cannot be
     *                                                    read as an option selection
     */
    public static function normalise(int|string $key, mixed $entry): ?array
    {
        if (\is_string($entry)) {
            // `{"Colour":"Black"}` puts the group in the key; `["Black"]` has no group,
            // and the group-less resolution path finds which facet holds the value.
            return ['option' => $entry, 'group' => \is_string($key) ? $key : null];
        }

        if (!\is_array($entry)) {
            return null;
        }

        return self::fromArrayEntry($entry);
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return array{option: string, group: ?string}|null
     */
    private static function fromArrayEntry(array $entry): ?array
    {
        $option = $entry['option'] ?? null;
        if (\is_string($option)) {
            $group = $entry['group'] ?? null;

            return ['option' => $option, 'group' => \is_string($group) ? $group : null];
        }

        return VariantSelectionPair::read($entry);
    }
}
