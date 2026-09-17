<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * How many claims the shipped detector already found the shop's own prose does not support.
 *
 * Nothing is judged here — {@see \Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit} did
 * that when the turn ran, and this only counts what it wrote to `claims.audit`. Which is the point:
 * the number is free, deterministic and reproducible from an export, unlike anything the nightly
 * judge produces.
 *
 * **Split out of {@see DescriptionCoverage} because the gate rejected the combination**, and the
 * boundary turned out to be the better one: coverage is about whether a description was handed over
 * at all, this is about whether the reply stayed inside it. The two answer different merchant
 * questions and are revised for different reasons.
 */
final readonly class ClaimAudit
{
    private function __construct(
        public int $unsupportedClaims,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $claims = 0;

        foreach ($traces as $trace) {
            foreach ($trace->eventsOfStage('claims.audit') as $event) {
                // `is_array` rather than `?? []`: a payload value is `mixed`, and a row written by
                // an older version — or by a third party's own audit — may hold a count rather
                // than a list. Counting a non-array would be a TypeError inside a nightly task
                // nobody is watching.
                $recorded = $event['payload']['unsupportedFactClaims'] ?? null;
                $claims += \is_array($recorded) ? \count($recorded) : 0;
            }
        }

        return new self($claims);
    }
}
