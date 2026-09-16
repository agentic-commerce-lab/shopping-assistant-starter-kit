<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Searches that found nothing, and searches the shop could only call "many".
 *
 * The two halves are one merchant action each, in opposite directions. Nothing found means the
 * catalogue lacks the product or the words for it. "Many" means the shopper was handed a category
 * rather than an answer, and the facets are too coarse to narrow it.
 *
 * ## Read off `tool.result`, not off `retrieve` — and that was a real bug
 *
 * The first version of this class read `query`, `total` and `many` from the `retrieve` payload.
 * **None of those keys exists there.** A dry run against an archived corpus of 131 real
 * conversations on 2026-09-16 reported zero empty searches, which is what exposed it: `retrieve`
 * carries `hits`, `categoryId`, `retainedIds` and `candidateLimit`, while the count the model was
 * actually handed is `total` on the `tool.result` that follows. The metric was silently dead — the
 * worst state for a control, because it reads as good news. Reading the real keys finds 8 empty
 * searches in that corpus, for words including *"Sattel"*, *"headphones"* and *"rower"*.
 *
 * `total` rather than `retrieve`'s `hits` deliberately: `hits` is the gateway's raw candidate count,
 * and a search that found candidates and then filtered them all away still handed the model nothing.
 * What the model saw is what the shopper saw.
 *
 * ## `many`, and no threshold of our own
 *
 * The over-cap half reads only `many === true`, which is
 * {@see \Swag\AssistantStarterKit\Core\Tool\SearchResultCounts}' own statement that it will not put
 * a figure behind the count. Inferring the cap from `matched >= 100` would be this class inventing
 * a threshold the shop never published, and the cap is not a public constant. The cost is that
 * traces written before that flag existed report nothing here; the alternative was a number we
 * would have had to keep in sync by hand.
 *
 * ## The terms are capped
 *
 * "12 searches found nothing" is not actionable; the twelve words are. But an uncapped list turns
 * the run row into a text table and reopens D23's split, so 25 distinct terms per half is the
 * limit — enough to act on, not enough to become an archive.
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
            $term = '';

            foreach ($trace->events as $event) {
                $term = SearchedTerm::carriedThrough($event, $term);
                $payload = $event['stage'] === 'tool.result' ? $event['payload'] : [];

                if (($payload['total'] ?? null) === 0) {
                    ++$empty;
                    $emptyTerms[$term] = true;
                }

                if (($payload['many'] ?? null) === true) {
                    ++$overCap;
                    $overCapTerms[$term] = true;
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
        // The empty key is dropped rather than rendered: a count without a term is still a count,
        // but a blank row in the administration is something a merchant cannot act on.
        unset($seen['']);

        return array_values(\array_slice(array_keys($seen), 0, self::MAX_TERMS));
    }
}
