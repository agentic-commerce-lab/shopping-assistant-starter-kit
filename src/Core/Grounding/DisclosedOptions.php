<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The option values a run handed the model **without a card behind them**, read back out of the trace.
 *
 * **A deliberate mirror of {@see \Swag\AssistantStarterKit\Core\Tool\GivenDescriptions}**, down to the
 * reasoning: the trace is the only record of what was handed over, so anything wanting to audit a
 * reply against what the shop said has to come here. Two readers of one trace contract beat a copy
 * that drifts.
 *
 * ## Why it is needed
 *
 * The property audit measures a claim against the cards the tools retrieved
 * ({@see \Swag\AssistantStarterKit\Tests\Core\Grounding\PropertyClaimsMeasuredAgainstRetrievedTest},
 * 2026-09-01). Retrieval is not the only way the shop states an option value, and the other two are
 * features rather than accidents:
 *
 * - `search_products` returns a `families` block for every family narrowing truncated — see
 *   {@see \Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies}, built so a 30-variant family is
 *   describable when only five members fit the candidate window.
 * - the viewing line names the open product's whole family — see
 *   {@see \Swag\AssistantStarterKit\Core\Prompt\ViewingContext}, so *"what sizes is this in?"* is
 *   answerable with no tool call at all.
 *
 * Measured on the live shop 2026-09-02, before this existed: the assistant answered
 * *"what sizes is this available in?"* with the bag's real size run, and the reply carried
 * `unbackedPropertyClaims: ['M', 'XL', 'XS']` — which the widget renders as a note telling the shopper
 * the card below is authoritative. It shows one size.
 *
 * **Every disclosure in the run, not the last one.** Same rule `GivenDescriptions` applies to
 * descriptions and `RetrievedPassages` to passages, for the same reason: a value the shop stated in
 * turn one is not an invention when restated in turn two.
 *
 * ## The payload is a flat list, and that is load-bearing
 *
 * Both recorders hold their values grouped (`{Size: [...], Colour: [...]}`) and both flatten through
 * {@see self::valuesOf()} before recording. Three reasons, worst first:
 *
 * 1. **Merging grouped maps is a trap.** `array_merge()` on group-keyed maps *overwrites*, so two
 *    truncated families that both have a `Size` group would record only the second one's sizes — a
 *    silent, partial disclosure, which reads to the audit as the model having invented the first
 *    family's sizes. That is this class's own defect reintroduced one level down, and it was written
 *    here once before being caught. Flat lists concatenate instead.
 * 2. {@see BackedPropertyValues} is a flat set with no notion of group or product anyway, so a
 *    grouped payload would only invite a reader to believe an attribution this pipeline cannot make.
 * 3. One loop instead of two nested ones, which keeps this class inside the complexity budget mago
 *    sums across every method in it.
 *
 * ## Where it lives, and why not beside a recorder
 *
 * `GivenDescriptions` sits in `Core\Tool` next to the one tool that records it. This has **two**
 * unrelated recorders — a tool and the agent factory — so it lives with its single consumer instead,
 * which is also the one place that has to agree with both of them on {@see self::STAGE}.
 */
final readonly class DisclosedOptions
{
    /**
     * The trace stage both disclosure sites record under.
     *
     * A shared constant rather than a literal at each end: three files have to agree on this string
     * and none of them can see the others, so a typo would silently mean "the shop disclosed
     * nothing" — a false warning on a true sentence, the exact defect this fixes.
     */
    public const STAGE = 'options.disclosed';

    /**
     * One or more grouped option maps as the flat, deduplicated list a recorder writes.
     *
     * See the class docblock for why flat. Deduplicated here rather than on read, so the trace a
     * merchant opens does not repeat one size per family that happens to offer it.
     *
     * @param list<array<string, list<string>>> $optionMaps
     *
     * @return list<string>
     */
    public static function valuesOf(array $optionMaps): array
    {
        $values = [];

        foreach ($optionMaps as $options) {
            foreach ($options as $groupValues) {
                $values = [...$values, ...$groupValues];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    public static function from(TraceRecorder $trace): array
    {
        $values = [];

        foreach ($trace->events() as $event) {
            if ($event->stage === self::STAGE) {
                $values = [...$values, ...self::valuesIn($event->payload['options'] ?? null)];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Reads the flat list a recorder writes, tolerating anything else.
     *
     * A payload is JSON round-tripped through the database on the read side of a persisted trace, so
     * nothing about its shape can be assumed from the writer's types. A malformed entry contributes
     * nothing rather than throwing: missing a disclosure costs one unnecessary warning, and throwing
     * here costs the shopper their whole reply.
     *
     * @return list<string>
     */
    private static function valuesIn(mixed $options): array
    {
        $values = [];

        foreach (\is_array($options) ? $options : [] as $value) {
            if (\is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
