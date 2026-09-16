<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Searches that found nothing, and searches that hit the match cap.
 *
 * The two halves are one merchant action each, in opposite directions. Nothing found means the
 * catalogue lacks the product or the words for it. Over the cap means the shopper was handed a
 * category rather than an answer, and the facets are too coarse to narrow it.
 *
 * **The terms matter more than the counts, and are capped anyway.** "12 searches found nothing" is
 * not actionable; the twelve words are. But an uncapped list turns the run row into a text table
 * and reopens D23's split, so 25 distinct terms per half is the limit — enough to act on, not
 * enough to become an archive.
 *
 * A search with no recorded query still counts. A count without a term is a count; an empty string
 * in the term list would render in the administration as a blank row nobody can act on.
 */
final readonly class SearchOutcomes
{
    public const MAX_TERMS = 25;

    /**
     * @param list<string> $emptyTerms
     * @param list<string> $overCapTerms
     */
    private function __construct(
        public int $searchesEmpty,
        public int $searchesOverCap,
        public array $emptyTerms,
        public array $overCapTerms,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $empty = 0;
        $overCap = 0;
        $emptyTerms = [];
        $overCapTerms = [];

        foreach ($traces as $trace) {
            foreach ($trace->eventsOfStage('retrieve') as $event) {
                $query = \is_string($event['payload']['query'] ?? null) ? $event['payload']['query'] : '';

                if (($event['payload']['total'] ?? null) === 0) {
                    ++$empty;
                    $emptyTerms[$query] = true;
                }

                if (($event['payload']['many'] ?? false) === true) {
                    ++$overCap;
                    $overCapTerms[$query] = true;
                }
            }
        }

        return new self($empty, $overCap, self::terms($emptyTerms), self::terms($overCapTerms));
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private static function terms(array $seen): array
    {
        unset($seen['']);

        return array_values(\array_slice(array_keys($seen), 0, self::MAX_TERMS));
    }
}
