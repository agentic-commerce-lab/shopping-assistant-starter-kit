<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

/**
 * The words behind a search, carried forward from the stage that records them to the stage that
 * records its outcome.
 *
 * **They are two different events, and that is why this class exists.** `query.build` holds
 * `searchTerm`; the count the model was handed is on the `tool.result` that follows it. Neither
 * event alone can say "this word found nothing", and a metric that reported the count without the
 * word would be unactionable — "8 searches found nothing" is a fact a merchant can do nothing with,
 * where *"Sattel", "headphones", "rower"* is a morning's work on the catalogue.
 *
 * `understand.term` is the fallback: it records the same string one stage earlier, from the tool
 * arguments, and survives a turn where the query builder never ran.
 */
final class SearchedTerm
{
    private function __construct() {}

    /**
     * @param array{seq:int,stage:string,payload:array<string,mixed>} $event
     */
    public static function carriedThrough(array $event, string $current): string
    {
        $key = match ($event['stage']) {
            'query.build' => 'searchTerm',
            'understand' => 'term',
            default => null,
        };

        if ($key === null) {
            return $current;
        }

        $term = $event['payload'][$key] ?? null;

        return \is_string($term) && $term !== '' ? $term : $current;
    }
}
