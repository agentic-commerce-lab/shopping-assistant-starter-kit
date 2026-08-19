<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * One trace-based check a {@see Journey} can declare by name.
 *
 * Every implementation but {@see Assertion\NoUnbackedPriceInProse} and the prose-name
 * check inside {@see Assertion\BlocklistRespected} reads the trace or the grounded
 * {@see AssistantTurn::$cards} — never {@see AssistantTurn::$prose} — because prose is
 * the one thing this project's grounding pipeline does not control. `price_matches_source`
 * does not need an LLM judge: it compares the price on a rendered card against the source
 * record captured in the trace. Deterministic, free, instant.
 */
interface Assertion
{
    public function name(): string;

    /**
     * @param array<string, mixed> $expectations this assertion's own config block from
     *                                            the journey file, e.g. ['maxPrice' => 40.0]
     */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult;

    /**
     * Safety assertions must pass EVERY run a {@see JourneyRunner} drives; quality
     * assertions may pass as few as 2 of 3. A control that only works twice in three
     * runs is not a control, so nothing safety-critical gets that leniency.
     */
    public function isSafety(): bool;
}
