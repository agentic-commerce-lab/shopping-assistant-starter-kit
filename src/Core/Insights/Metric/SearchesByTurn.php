<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Every product search a conversation made, grouped by the turn it belongs to.
 *
 * ## Two shapes, both real, both from one conversation
 *
 * The model sometimes builds several queries and the trace carries **one** `tool.result` for the
 * batch, and sometimes builds one query per result. Measured 2026-09-17, seq numbers from a single
 * conversation:
 *
 *     seq  9  query.build  Reifen 28 Zoll        seq 37  query.build  Reifen 28
 *     seq 13  query.build  Reifen 28             seq 43  tool.result  total=0
 *     seq 18  query.build  tire 28               seq 47  query.build  Reifen
 *     seq 22  tool.result  total=5 matched=100   seq 51  tool.result  total=5 matched=100
 *
 * So a result consumes the FIRST term pending since the last result and discards the rest. In the
 * left shape that yields `Reifen 28 Zoll` — the query built from the shopper's sentence, rather
 * than the assistant's own narrowing. In the right shape each term keeps its own result.
 *
 * ## Why grouping by turn matters more than it looks
 *
 * Classifying a turn by its FIRST search alone was the previous rule, and it reported the right
 * turn's word for the wrong reason: the second turn above showed the shopper three products, and
 * its first search had returned nothing. "The first search of a turn" is not "what the turn
 * achieved", so `Reifen 28` was published to a merchant as a catalogue gap in a shop holding over a
 * hundred 28-inch tyres.
 *
 * Grouping leaves that judgement to {@see SearchOutcomes}, which can then ask the only question a
 * merchant cares about: did this turn give the shopper anything?
 *
 * A turn that searched nothing — an escalation, a shop-information answer — produces no group at
 * all rather than an empty one, because an empty group divides into every ratio drawn from these
 * numbers.
 */
final class SearchesByTurn
{
    private function __construct() {}

    /**
     * @return list<list<array{term: string, total: int, matched: int}>>
     */
    public static function in(ConversationTrace $trace): array
    {
        $turns = [];
        $current = [];
        $term = '';

        foreach ($trace->events as $event) {
            if ($event['stage'] === 'turn.end') {
                if ($current !== []) {
                    $turns[] = $current;
                }

                $current = [];
                $term = '';

                continue;
            }

            $term = SearchedTerm::carriedThrough($event, $term);
            $search = self::searchIn($event, $term);

            if ($search === null) {
                continue;
            }

            $current[] = $search;
            // The term is cleared rather than kept: it has been spent on this result, and the next
            // query built in this turn is a new search with a word of its own.
            $term = '';
        }

        if ($current !== []) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * @param array{seq:int,stage:string,payload:array<string,mixed>} $event
     *
     * @return array{term: string, total: int, matched: int}|null
     */
    private static function searchIn(array $event, string $term): ?array
    {
        $total = $event['stage'] === 'tool.result' ? $event['payload']['total'] ?? null : null;

        // An int `total` is what marks a product search's result. `browse_categories` carries
        // `departments` and `note`, and must neither count as a search nor spend a pending term.
        if (!\is_int($total)) {
            return null;
        }

        $matched = $event['payload']['matched'] ?? null;

        return ['term' => $term, 'total' => $total, 'matched' => \is_int($matched) ? $matched : $total];
    }
}
