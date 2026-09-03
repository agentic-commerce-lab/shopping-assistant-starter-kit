<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * One retrieval attempt and its three relaxations, in the order their diagnoses get more general:
 * the exact query, then without the option filters, then with the words shortened, then both at once.
 *
 * The fourth read was added on 2026-09-03 after `probe --ask="i want purple tyres"` returned
 * `no_result` on a shop selling four tyres — see {@see self::composed()}. The post-steps every one of
 * the four gets live in {@see RetainedCards}.
 *
 * ## Why it is a class rather than a block inside the tool
 *
 * Because it runs **twice**. {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} runs it
 * once constrained to the category the shopper is browsing and, if that yields nothing, again
 * without the constraint. That is the only shape which gets both the aisle's answer and the shop's,
 * and both live findings that led here are worth keeping:
 *
 * - Giving the category up **last** — relaxations inside the cage, then the cage removed with the
 *   words restored — failed the `page_context_not_a_cage` eval on its first run: "gloves" while
 *   browsing Jerseys returned nothing, because reaching "Commuter Glove" needs the relaxed term AND
 *   no category, and applying them one at a time never produces that pair.
 * - Giving it up **first** fixed that and broke the opposite case: "bottles" while browsing Bottles
 *   returned the Alloy Bottle *Cage*, from another category, ranked above the bottles — relaxation
 *   went shop-wide before it had been tried where the shopper was standing.
 *
 * Neither is an ordering problem. It is two passes.
 *
 * ## What it records
 *
 * A `retrieve` event per pass, deliberately: two retrievals did happen, and a trace showing one
 * would describe a search the shop did not run. {@see \Swag\AssistantStarterKit\Eval\Assertion\BlocklistSurvivors}
 * already reads every `retrieve` event rather than the last (ruling R42).
 */
final class RetrievalPass
{
    private function __construct() {}

    /**
     * The full scope — including blockedProductIds/blockedCategoryIds — goes to retrieval rather
     * than a stripped-down one: a well-behaved gateway should never even fetch a blocked product,
     * which is strictly less exposure than fetching it and relying on removal afterwards. The
     * caller's `BlocklistFilter` still runs unconditionally as the second line of defence, for a
     * gateway whose scope mapping is incomplete.
     *
     * @return array{cards: list<ProductCard>, note: ?string, budgetNarrowed: bool}
     */
    public static function run(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        QueryBuildResult $buildResult,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): array {
        // Enforced before the `retrieve` event, not after, so `retainedIds` names the cards that
        // actually survived rather than a set the next line already narrowed.
        $first = RetainedCards::of($gateway->search($query, $scope), $query, $query->term, $trace);
        $narrowed = $first->budgetNarrowed;
        $trace->record('retrieve', [
            'hits' => \count($first->cards),
            'retainedIds' => array_map(static fn(ProductCard $card): string => $card->id, $first->cards),
            'candidateLimit' => $query->candidateLimit,
            'categoryId' => $query->categoryId,
        ]);

        if ($first->cards !== []) {
            return ['cards' => $first->cards, 'note' => null, 'budgetNarrowed' => $narrowed];
        }

        foreach (self::relaxations($gateway, $query, $buildResult, $scope, $trace) as $note => $attempt) {
            $retained = RetainedCards::of($attempt['cards'], $query, $attempt['term'], $trace);
            $narrowed = $narrowed || $retained->budgetNarrowed;

            if ($retained->cards !== []) {
                return ['cards' => $retained->cards, 'note' => $note, 'budgetNarrowed' => $narrowed];
            }
        }

        return ['cards' => [], 'note' => null, 'budgetNarrowed' => $narrowed];
    }

    /**
     * The three relaxations, in the order their diagnoses get more general, each yielded under the
     * note that describes it and paired with the words the gateway actually searched.
     *
     * A generator rather than three inline blocks so a later relaxation costs a `yield` instead of
     * another branch in `run()` — mago sums cyclomatic complexity across a class, and the fourth
     * read added on 2026-09-03 is what took the old shape over the budget.
     *
     * Lazy, and that is the whole cost model: nothing here runs until `run()` asks for it, and it
     * stops asking the moment something comes back. A search that works pays for none of them.
     *
     * @return \Generator<string, array{cards: list<ProductCard>, term: ?string}>
     */
    private static function relaxations(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        QueryBuildResult $buildResult,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): \Generator {
        // First, because it is the most specific diagnosis. An applied option filter that eliminated
        // everything is the "your products are missing attribute X" case, not the "we do not sell
        // it" case. See UnmatchedOptionRetry for the measurement, and for why silence was wrong.
        $withoutOptions = UnmatchedOptionRetry::search(
            $gateway,
            $query,
            $buildResult->selectionFilters,
            $scope,
            $trace,
        );

        if ($withoutOptions !== null) {
            // The same words as the first pass — this retry relaxed the options, not the term.
            yield UnmatchedOptionRetry::NOTE => ['cards' => $withoutOptions, 'term' => $query->term];
        }

        // Second: the words themselves may simply not be in the shop's keyword index. "gloves" found
        // nothing in a shop that sells the Commuter Glove — the storefront's own search box has the
        // same gap — and the model then told a shopper the shop carries no gloves.
        $relaxed = RelaxedTermRetry::search($gateway, $query, $scope, $trace);

        if ($relaxed !== null) {
            // Judged against the SHORTENED words, because those are the ones the gateway actually
            // searched. The shopper's own spelling would let a fragment back in: "cats" appears
            // nowhere in "Chain Wear Indicator", so it reads as a match on a field the card does not
            // carry, while the "cat" the gateway really used is a fragment of "indiCATor".
            yield RelaxedTermRetry::NOTE => ['cards' => $relaxed, 'term' => RelaxedTermRetry::relax($query->term)];
        }

        // Third, and only because each of the two above failed for the OTHER's reason.
        yield UnmatchedOptionRetry::NOTE . ' ' . RelaxedTermRetry::NOTE => self::composed(
            $gateway,
            $query,
            $buildResult,
            $scope,
            $trace,
        );
    }

    /**
     * The relaxed term AND no option filters, because applying them one at a time never produces
     * that pair.
     *
     * **Measured on staging, 2026-09-03**, running the reported turn through
     * `probe --ask="i want purple tyres"` against a shop that sells four tyres:
     *
     * ```
     * #9  retrieve                 hits: 0
     * #10 retrieve.without_options droppedFields: ["properties.Colour"]  hits: 0
     * #11 retrieve.relaxTerm       relaxedTerm: tyre                     hits: 0
     * #18 turn.end                 outcome: no_result
     * ```
     *
     * with, in the same session, `--search="tyres"` -> 0, `--search="tyre"` -> 4 and
     * `--search="purple"` -> 0. So `without_options` dropped the colour and kept the plural, which
     * matches nothing; `relaxTerm` fixed the plural and kept the colour — deliberately, since it
     * "relaxes ONE thing" — which matches nothing either. The shopper was told the search found
     * nothing while standing in front of the products.
     *
     * This is the argument this class's own docblock already makes about the shopper's category:
     * *"Neither is an ordering problem. It is two passes."* Same shape, option filter in the
     * category's place, same answer.
     *
     * **It cannot run on a search that works.** Every caller above returns first, so this is a
     * fourth gateway read only for a query that has already come back empty three times. And it is
     * assembled from the two existing retries rather than reimplementing either, so the blocklist,
     * the scope and the stated budget apply exactly as they do to a first-pass search.
     *
     * @return array{cards: list<ProductCard>, term: ?string} empty cards when there is nothing left
     *     to compose — no term to relax, or no option filter to drop
     */
    private static function composed(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        QueryBuildResult $buildResult,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): array {
        $term = RelaxedTermRetry::relax($query->term);

        if ($term === null) {
            return ['cards' => [], 'term' => null];
        }

        $cards = UnmatchedOptionRetry::search(
            $gateway,
            $query->withTerm($term),
            $buildResult->selectionFilters,
            $scope,
            $trace,
            'retrieve.relaxTerm_without_options',
        );

        return ['cards' => $cards ?? [], 'term' => $term];
    }
}
