<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Candidates for one {@see ShopperIntent}: build the query, retrieve inside the shopper's aisle, and
 * retrieve again without it if the aisle had nothing.
 *
 * ## Why this is its own class
 *
 * It was inline in {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} until 2026-08-26,
 * when that tool learned to take several search terms and therefore had to run this once per term. Two
 * things forced the extraction rather than a loop in place: the tool was 385 lines against this
 * project's 400-line cap, and mago sums cyclomatic complexity across a class's methods, so a loop
 * around a block that already carries the P9 two-pass retry would have taken it over.
 *
 * The boundary is also the right one. Everything here is about **one** intent; everything left in the
 * tool is about the turn — the argument bounds, resolution over the merged window, the blocklist,
 * narrowing, and what the model is told.
 */
final readonly class IntentRetrieval
{
    public function __construct(
        private CommerceGatewayInterface $gateway,
        private QueryBuilder $queryBuilder,
        private TraceRecorder $trace,
        /**
         * The category the shopper is browsing, or null. A default constraint on this turn's searches,
         * not a bound the model chose — so it is retried away rather than enforced when it costs the
         * shopper an answer (P9).
         */
        private ?string $browsingCategoryId = null,
    ) {}

    public function run(
        ShopperIntent $intent,
        FacetSet $facets,
        int $limit,
        int $candidateLimit,
        CatalogScope $scope,
    ): IntentCandidates {
        $this->trace->record('understand', [
            'term' => $intent->term,
            'priceMax' => $intent->priceMax,
            'priceMin' => $intent->priceMin,
            'brand' => $intent->brand,
            'selectionCount' => \count($intent->selections),
            'source' => 'tool_arguments',
        ]);

        $buildResult = $this->queryBuilder->build($intent, $facets);
        $this->trace->record('query.build', [
            'filtersApplied' => array_map(static fn($filter) => $filter->field, $buildResult->query->filters),
            'filtersDropped' => $buildResult->droppedFields,
            'searchTerm' => $buildResult->query->term,
            'limitRequested' => $limit,
            'candidateLimit' => $candidateLimit,
            'categoryId' => $this->browsingCategoryId,
        ]);

        // QueryBuilder::build() does not carry a limit — ShopperIntent has none — so the guarded limit
        // and the candidate window are applied here, on top of the query it produced, rather than being
        // silently dropped on the floor.
        $query = new ProductQuery(
            term: $buildResult->query->term,
            filters: $buildResult->query->filters,
            limit: $limit,
            sort: $buildResult->query->sort,
            candidateLimit: $candidateLimit,
            categoryId: $this->browsingCategoryId,
        );

        // The full scope — including blockedProductIds/blockedCategoryIds — goes to retrieval, not a
        // stripped-down one: a well-behaved gateway should never even fetch a blocked product, and that
        // is strictly less exposure than fetching it and relying on removal afterwards. The caller's
        // BlocklistFilter still runs unconditionally as the second line of defence.
        // The caged pass: everything the shopper asked for, inside the aisle they are standing in.
        ['cards' => $cards, 'note' => $note, 'budgetNarrowed' => $budgetNarrowed] = RetrievalPass::run(
            $this->gateway,
            $query,
            $buildResult,
            $scope,
            $this->trace,
        );

        if ($cards === [] && $this->browsingCategoryId !== null) {
            ['cards' => $cards, 'note' => $note, 'budgetNarrowed' => $uncagedNarrowed] = $this->uncaged(
                $query,
                $buildResult,
                $scope,
            );
            $budgetNarrowed = $budgetNarrowed || $uncagedNarrowed;
        }

        return new IntentCandidates(
            cards: $cards,
            note: $note,
            buildResult: $buildResult,
            windowSaturated: \count($cards) === $query->retrievalLimit(),
            budgetNarrowed: $budgetNarrowed,
        );
    }

    /**
     * P9 — the aisle is a helpful default, not a cage — and it is given up only after every relaxation
     * has been tried *inside* it, which is a correction on two earlier attempts.
     *
     * Giving it up LAST was the first shape, and the `page_context_not_a_cage` eval falsified it live:
     * the relaxations ran inside the cage while this pass restored the unrelaxed words, so "gloves" in
     * Jerseys never met the pair it needs — relaxed term AND no category.
     *
     * Giving it up FIRST fixed that and broke the opposite case, measured against the fixture:
     * "bottles" while browsing Bottles then returned the Alloy Bottle *Cage* — from another category —
     * ranked above the bottles, because relaxation went shop-wide before it had been tried where the
     * shopper was standing.
     *
     * Neither ordering works, because this is not an ordering problem: it is two passes. The whole
     * chain runs caged, and if that yields nothing the whole chain runs again uncaged. The shopper gets
     * the aisle's answer when the aisle has one, and the shop's answer when it does not — and page
     * context can still never make the assistant worse than its absence.
     *
     * @return array{cards: list<\Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard>, note: ?string,
     *     budgetNarrowed: bool}
     */
    private function uncaged(ProductQuery $query, QueryBuildResult $buildResult, CatalogScope $scope): array
    {
        $this->trace->record('retrieve.without_category', ['categoryId' => $this->browsingCategoryId]);

        return RetrievalPass::run($this->gateway, $query->withoutCategory(), $buildResult, $scope, $this->trace);
    }
}
