<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The product descriptions a run handed the model, read back out of the trace.
 *
 * **A deliberate mirror of {@see \Swag\AssistantStarterKit\Core\ShopInfo\RetrievedPassages}**, down to
 * the reasoning: the trace is the only record of what was handed over, so anything wanting to audit a
 * reply against the text behind it has to come here. Two readers of one trace contract beat a copy
 * that drifts — and the copy that drifts is always the one nobody reads.
 *
 * **Every comparison in the run, not the last one.** One recorder spans a whole conversation
 * (ruling R84), and a claim sourced from turn one's comparison is not an invention when restated in
 * turn two. Same rule `RetrievedPassages` applies to passages, same reason.
 */
final readonly class GivenDescriptions
{
    /** The trace stage {@see CompareProductsTool} records under. */
    public const STAGE = 'compare.descriptions';

    /**
     * @return list<string>
     */
    public static function from(TraceRecorder $trace): array
    {
        $descriptions = [];

        foreach ($trace->events() as $event) {
            if ($event->stage !== self::STAGE) {
                continue;
            }

            $found = $event->payload['descriptions'] ?? null;

            foreach (\is_array($found) ? $found : [] as $description) {
                if (\is_string($description) && $description !== '') {
                    $descriptions[] = $description;
                }
            }
        }

        return $descriptions;
    }
}
