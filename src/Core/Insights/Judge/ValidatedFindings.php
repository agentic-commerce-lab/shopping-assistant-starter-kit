<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

/**
 * What the judge said, and how much of it was thrown away.
 *
 * **`discarded` exists because without it "no findings" is uninterpretable.** Measured on
 * 2026-09-16: a replay over 33 real conversations reported nothing, and nothing in the output could
 * distinguish a model that answered with an empty array from one whose every finding was dropped by
 * {@see JudgeFindingRow}'s quote check. Those two mean opposite things — the first is a quiet night,
 * the second is a broken control — and a precision figure cannot be read off a number that conflates
 * them.
 *
 * The same argument as `CompletedRun::$dropped`, and the same mistake avoided twice: a guard that
 * silently removes things has to say how many.
 */
final readonly class ValidatedFindings
{
    /**
     * @param list<JudgeFinding>     $findings
     * @param array<string, int>     $discardReasons which check refused how many rows, so a
     *                                              discarded batch names its own fix
     */
    public function __construct(
        public array $findings,
        public int $discarded,
        public array $discardReasons = [],
    ) {}
}
