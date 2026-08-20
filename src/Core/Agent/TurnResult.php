<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * One turn plus the trace it produced.
 *
 * The two travel together because they are only useful together: the turn is what the shopper sees,
 * and the trace is the only thing that can say whether it was earned. Acceptance criterion A6
 * requires every turn to persist its trace, so a runner that returned the turn alone would make the
 * criterion impossible to satisfy from the caller's side.
 */
final readonly class TurnResult
{
    public function __construct(
        public AssistantTurn $turn,
        public TraceRecorder $trace,
    ) {}
}
