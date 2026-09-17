<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * The first product search of each turn, with the words it was built from.
 *
 * ## Why only the first
 *
 * **The assistant retries a failed search in another language, and counting the retries turns one
 * shopper into three.** Measured on 2026-09-17 against a German catalogue: "Habt ihr Zündkerzen für
 * eine Yamaha MT-07?" produced searches for `Zündkerzen` and then `spark plug`; "Ich brauche einen
 * Fahrradhelm" produced `Helm`, `Gravel`, `Helmet`, `helmet`. A metric over every search reported
 * five empty searches from three shoppers and listed `spark plug` and `Helmet` — words nobody typed.
 * A merchant reading that list would stock spark plugs for someone asking about a motorbike in
 * German.
 *
 * The first search of a turn is built from what the shopper wrote. Everything after it is the
 * assistant reacting to its own miss, which is a fact about the assistant and not about the
 * catalogue.
 *
 * **It also fixes the direction of the count.** A turn whose first search succeeded is not a
 * catalogue gap even when a later search in the same turn found nothing — and that happens, because
 * the assistant searched `brake pads` after `Bremsbelag` had already returned three products.
 *
 * ## The known undercount
 *
 * A turn with two genuine needs — "zwei Schläuche und eine Kette" — contributes one search here, so
 * the second need is invisible. Stated rather than fixed: distinguishing a second need from a retry
 * needs to know whether the words are a translation of the first, and the error runs in the safe
 * direction. A metric a merchant acts on should under-report a gap rather than invent one.
 */
final class FirstSearchOfTurn
{
    private function __construct() {}

    /**
     * @return list<array{term: string, total: int, matched: int}>
     */
    public static function in(ConversationTrace $trace): array
    {
        $searches = [];
        $term = '';
        $counted = false;

        foreach ($trace->events as $event) {
            if (!$counted) {
                $term = SearchedTerm::carriedThrough($event, $term);
            }

            if ($event['stage'] === 'turn.end') {
                $term = '';
                $counted = false;

                continue;
            }

            $total = $event['stage'] === 'tool.result' ? $event['payload']['total'] ?? null : null;

            // An int `total` is what marks a product search's result. `browse_categories` carries
            // `departments` and `note` and must neither be counted nor consume the turn's one slot.
            if ($counted || !\is_int($total)) {
                continue;
            }

            $matched = $event['payload']['matched'] ?? null;
            $searches[] = ['term' => $term, 'total' => $total, 'matched' => \is_int($matched) ? $matched : $total];
            $counted = true;
        }

        return $searches;
    }
}
