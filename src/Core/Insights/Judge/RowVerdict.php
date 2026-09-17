<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

/**
 * One row of the judge's answer, accepted or refused with a reason.
 *
 * **The reason is the whole point.** A replay over 33 real conversations on 2026-09-16 returned five
 * findings and discarded all five, and the output could say only that. Which of four checks refused
 * them is the difference between "the model paraphrases its quotes", "the model returns the
 * conversation id as a number" and "the model invents types" — three different fixes, none of them
 * guessable from a count.
 */
final readonly class RowVerdict
{
    private function __construct(
        public ?JudgeFinding $finding,
        public string $reason,
    ) {}

    public static function accepted(JudgeFinding $finding): self
    {
        return new self($finding, '');
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }
}
