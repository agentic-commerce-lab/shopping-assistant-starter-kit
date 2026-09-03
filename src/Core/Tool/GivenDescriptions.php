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
    /**
     * The trace stage the tools that hand over a product's own prose record under.
     *
     * Was `compare.descriptions` while {@see CompareProductsTool} was the only one. `get_product`
     * joined it on 2026-09-03, after a shopper asked how long the Front Light 800's battery lasts:
     * the answer is in that product's own description — *"Four hours on full, twelve on the commute
     * setting"* — and the reply was *"the shop's data does not include battery life"*, which was true
     * of what the model had been given and false of the shop. A stage named after one caller would
     * have made the second caller look like a different kind of event.
     */
    public const STAGE = 'descriptions.given';

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
