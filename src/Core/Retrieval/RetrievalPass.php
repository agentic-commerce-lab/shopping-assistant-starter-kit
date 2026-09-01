<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * One retrieval attempt and its two relaxations, in the order their diagnoses get more general:
 * the exact query, then without the option filters, then with the words shortened.
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
        $narrowed = false;
        $cards = self::budgeted($gateway->search($query, $scope), $query, $trace, $narrowed);
        $trace->record('retrieve', [
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
            'candidateLimit' => $query->candidateLimit,
            'categoryId' => $query->categoryId,
        ]);

        if ($cards !== []) {
            return ['cards' => $cards, 'note' => null, 'budgetNarrowed' => $narrowed];
        }

        // An applied option filter that eliminated everything is the "your products are missing
        // attribute X" case, not the "we do not sell it" case. See UnmatchedOptionRetry for the
        // measurement and for why silence was the wrong answer.
        $withoutOptions = UnmatchedOptionRetry::search(
            $gateway,
            $query,
            $buildResult->selectionFilters,
            $scope,
            $trace,
        );

        if ($withoutOptions !== null) {
            $withoutOptions = self::budgeted($withoutOptions, $query, $trace, $narrowed);
        }

        if ($withoutOptions !== null && $withoutOptions !== []) {
            return [
                'cards' => $withoutOptions,
                'note' => UnmatchedOptionRetry::NOTE,
                'budgetNarrowed' => $narrowed,
            ];
        }

        // Second, and after the option retry because that one is the more specific diagnosis: the
        // words themselves may simply not be in the shop's keyword index. "gloves" found nothing in
        // a shop that sells the Commuter Glove — the storefront's own search box has the same gap —
        // and the model then told a shopper the shop carries no gloves. See RelaxedTermRetry.
        $relaxed = RelaxedTermRetry::search($gateway, $query, $scope, $trace);

        if ($relaxed !== null) {
            $relaxed = self::budgeted($relaxed, $query, $trace, $narrowed);
        }

        if ($relaxed !== null && $relaxed !== []) {
            return ['cards' => $relaxed, 'note' => RelaxedTermRetry::NOTE, 'budgetNarrowed' => $narrowed];
        }

        return ['cards' => [], 'note' => null, 'budgetNarrowed' => $narrowed];
    }

    /**
     * The query's stated price range, enforced on the price the shopper will actually be shown.
     *
     * Applied to **every** card list this class can return, including both retries: the retries keep
     * the price clause (they relax options and words, not the budget), so a card that broke the
     * ceiling on the first read still breaks it on the third. See {@see StatedBudget} for why the
     * database filter cannot be trusted for this and what remains unfixed.
     *
     * A `price.enforced` event is recorded only when something was dropped. Recording it
     * unconditionally would put a no-op line in the trace of every search in a shop with no price
     * rules, which is nearly all of them; recording nothing at all would let cards vanish between two
     * events that both look complete.
     *
     * `$narrowed` is set — never cleared — whenever this drops something, because a caller needs to
     * know the SQL price range disagreed with the shopper's own prices even if a later retry happened
     * not to drop anything. See {@see ExactMatchCount} for what depends on it.
     *
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard>
     */
    private static function budgeted(array $cards, ProductQuery $query, TraceRecorder $trace, bool &$narrowed): array
    {
        $kept = StatedBudget::keep($cards, $query);

        if (\count($kept) === \count($cards)) {
            return $kept;
        }

        $narrowed = true;

        $keptIds = array_map(static fn(ProductCard $card): string => $card->id, $kept);
        $droppedIds = array_values(array_diff(
            array_map(static fn(ProductCard $card): string => $card->id, $cards),
            $keptIds,
        ));

        $trace->record('price.enforced', [
            'droppedCount' => \count($droppedIds),
            'droppedIds' => $droppedIds,
            // Named so a trace reader is not left guessing why a card the database returned is not
            // in the reply.
            'reason' => 'price outside the stated range once the shopper\'s own price was known',
        ]);

        return $kept;
    }
}
