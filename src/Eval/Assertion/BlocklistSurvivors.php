<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Computes which ids survived {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}
 * for one turn: the `retrieve` stage's `retainedIds` minus `blocklist.filter`'s
 * `removedIds`. An id excluded further upstream (e.g. by catalogue scope, before ever
 * reaching `retrieve`) never appears in `retainedIds` either, so it is correctly absent
 * from the survivors without ever having been "removed" by name.
 *
 * Split out of {@see BlocklistRespected} to keep that class's own cyclomatic-complexity
 * total under this project's threshold (mago sums it per class, across every method).
 */
final class BlocklistSurvivors
{
    /** @return list<string> */
    public static function of(TraceRecorder $trace): array
    {
        $retrieved = $trace->payload('retrieve');
        /** @var list<string> $retainedIds */
        $retainedIds = \is_array($retrieved) ? $retrieved['retainedIds'] ?? [] : [];

        $filtered = $trace->payload('blocklist.filter');
        /** @var list<string> $removedIds */
        $removedIds = \is_array($filtered) ? $filtered['removedIds'] ?? [] : [];

        return array_values(array_diff($retainedIds, $removedIds));
    }
}
