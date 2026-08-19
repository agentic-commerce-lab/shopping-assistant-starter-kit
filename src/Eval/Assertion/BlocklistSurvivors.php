<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Computes which ids survived {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}
 * across a (possibly multi-turn) run: the union of every `retrieve` event's
 * `retainedIds` minus the union of every `blocklist.filter` event's `removedIds`. An id
 * excluded further upstream (e.g. by catalogue scope, before ever reaching `retrieve`)
 * never appears in `retainedIds` either, so it is correctly absent from the survivors
 * without ever having been "removed" by name.
 *
 * Ruling R42: aggregates via {@see TraceEvents::payloads()} across EVERY `retrieve` and
 * `blocklist.filter` event, not just the last of each ({@see TraceRecorder::payload()}) —
 * a run with more than one search call (whether within one turn or across several turns)
 * would otherwise only ever see the most recent search's candidates, silently dropping
 * an earlier search's blocked-id exposure from consideration.
 *
 * Ruling R44: this diffs `retrieve.retainedIds` against `blocklist.filter.removedIds`,
 * two id spaces that {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} runs
 * BETWEEN — and which can remap a parent id to a different variant id. So this layer
 * cannot detect a blocked *variant* leaking past a parent-level block: if `retrieve`
 * returns a parent-scoped id that resolution then replaces with an unrelated variant id,
 * that variant id was never in `retainedIds` to begin with, and its absence from
 * `removedIds` says nothing about whether it should have been blocked. This holds today
 * only because `blocked_item`'s archetypes never select variant options. It is NOT the
 * only defence: {@see BlocklistRespected}'s check against {@see \Swag\AssistantStarterKit\Core\Agent\AssistantTurn::$cards}
 * catches a real leak independently, since {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::registerRetrieved()}
 * only ever receives survivors. The correct fix — diffing POST-resolution ids — is
 * recorded for the next plan; this class's guarantee is narrower than its "survivors of
 * the blocklist filter" name suggests until that lands.
 *
 * Split out of {@see BlocklistRespected} to keep that class's own cyclomatic-complexity
 * total under this project's threshold (mago sums it per class, across every method).
 */
final class BlocklistSurvivors
{
    /** @return list<string> */
    public static function of(TraceRecorder $trace): array
    {
        $retainedIds = [];
        foreach (TraceEvents::payloads($trace, 'retrieve') as $payload) {
            /** @var list<string> $ids */
            $ids = $payload['retainedIds'] ?? [];
            array_push($retainedIds, ...$ids);
        }

        $removedIds = [];
        foreach (TraceEvents::payloads($trace, 'blocklist.filter') as $payload) {
            /** @var list<string> $ids */
            $ids = $payload['removedIds'] ?? [];
            array_push($removedIds, ...$ids);
        }

        return array_values(array_diff(array_unique($retainedIds), $removedIds));
    }
}
