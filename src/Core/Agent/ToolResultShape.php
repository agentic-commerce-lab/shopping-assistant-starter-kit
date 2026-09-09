<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

/**
 * What a tool handed back, described rather than copied.
 *
 * ## The gap this closes
 *
 * A trace review of 34 real conversations on 2026-09-09 could not answer three of its own questions,
 * and all three had the same cause: the traces record that a tool was CALLED and never what it
 * returned.
 *
 * - The assistant stated a catalogue total of **279 products** in three separate conversations. There
 *   is no way to tell from any trace whether a tool said so or the model invented the same figure
 *   three times.
 * - {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation} attaches the shop's real
 *   departments to an empty search so the reply can point somewhere true. Whether the model was ever
 *   handed them, on any turn, is unknowable from the recorded events.
 * - Three replies presented a fraction of a result set as the whole of it. `matched` was in the tool
 *   reply saying otherwise — or was it? Nothing recorded it.
 *
 * ## Why a shape and not the payload
 *
 * The obvious fix is to log the result. Two reasons not to.
 *
 * **Size.** A search reply carries up to eight product summaries with their option values. Copying
 * that into the event table multiplies the trace of a normal conversation for data the card rows
 * already hold.
 *
 * **What a trace is allowed to contain.** `add_to_cart` and `go_to_checkout` read the shopper's live
 * cart, so their replies carry that shopper's own basket. A merchant-readable audit row is not the
 * place to copy it, and a rule that says "log tool results" would have quietly done so.
 *
 * So this records the questions above and nothing else: which keys came back, the counted fields by
 * value, and the presence of each disclosure note. Every one of the three unanswerable questions
 * becomes a query; no catalogue prose, no cart, no product names.
 */
final class ToolResultShape
{
    /**
     * Fields recorded by value, because they are the ones a review needs to check a sentence
     * against — and none of them is anybody's personal data.
     *
     * All five are integers or booleans by contract. Nothing textual is on this list and nothing
     * textual can join it: the moment a value here could be prose, this class becomes a second copy
     * of the catalogue and, for the two cart tools, of somebody's basket.
     *
     * @var list<string>
     */
    private const COUNTED = ['total', 'matched', 'more', 'withheld', 'quantity'];

    private function __construct() {}

    /**
     * @return array<string, bool|int|string> a flat payload, safe to write to the event table
     */
    public static function of(mixed $result): array
    {
        if (!\is_array($result)) {
            // A tool that returns a scalar or an object is described by its type alone. Nothing in
            // the shipped set does, and guessing at an unknown extension's payload is exactly what
            // this class exists not to do.
            return ['resultType' => get_debug_type($result)];
        }

        return [
            // **The key list is doing more work than it looks.** Two of the three unanswerable
            // questions were about PRESENCE — was a note attached, were the departments handed over
            // — and a key list answers both without copying a word of either. An earlier draft
            // counted the departments and flagged each note separately; both were the same fact
            // twice, and dropping them took this class under the complexity budget as a side effect
            // rather than as the reason.
            'keys' => implode(',', array_map(static fn(mixed $key): string => (string) $key, array_keys($result))),
            ...self::counted($result),
        ];
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return array<string, bool|int>
     */
    private static function counted(array $result): array
    {
        $counted = [];

        foreach (self::COUNTED as $field) {
            $value = $result[$field] ?? null;

            if (\is_int($value) || \is_bool($value)) {
                $counted[$field] = $value;
            }
        }

        return $counted;
    }
}
