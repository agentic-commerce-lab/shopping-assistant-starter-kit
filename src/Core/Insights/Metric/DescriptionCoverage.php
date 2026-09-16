<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * How often the model was answering about a product whose description it never received.
 *
 * **This counts the hand-over, not the content.** Measured on 2026-09-16 over 148 real turns,
 * `descriptions.given` fired on 5 — so the shop's descriptions were almost never the model's
 * problem; their delivery was. "Was a detail actually asked for" would be the more interesting
 * question and is a judgement, not a count. This one is computable, and it was the one that mattered.
 *
 * **A turn that returned no product at all counts for neither half.** A shop-information turn has
 * nothing to describe, and counting it as a miss would make the coverage figure look worse the more
 * questions the assistant answers well.
 */
final readonly class DescriptionCoverage
{
    private function __construct(
        public int $turnsWithDescription,
        public int $turnsWithoutDescription,
        public int $unsupportedClaims,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $with = 0;
        $without = 0;
        $claims = 0;

        foreach ($traces as $trace) {
            $sawProducts = false;
            $sawDescriptions = false;

            foreach ($trace->events as $event) {
                if ($event['stage'] === 'tool.result') {
                    $sawProducts = true;
                }

                if ($event['stage'] === 'descriptions.given') {
                    $sawDescriptions = true;
                }

                if ($event['stage'] === 'claims.audit') {
                    $claims += \count($event['payload']['unsupportedFactClaims'] ?? []);
                }

                if ($event['stage'] !== 'turn.end') {
                    continue;
                }

                if ($sawProducts && $sawDescriptions) {
                    ++$with;
                } elseif ($sawProducts) {
                    ++$without;
                }

                $sawProducts = false;
                $sawDescriptions = false;
            }
        }

        return new self($with, $without, $claims);
    }
}
