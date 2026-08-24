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
     * @return array{cards: list<ProductCard>, note: ?string}
     */
    public static function run(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        QueryBuildResult $buildResult,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): array {
        $cards = $gateway->search($query, $scope);
        $trace->record('retrieve', [
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
            'candidateLimit' => $query->candidateLimit,
            'categoryId' => $query->categoryId,
        ]);

        if ($cards !== []) {
            return ['cards' => $cards, 'note' => null];
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

        if ($withoutOptions !== null && $withoutOptions !== []) {
            return ['cards' => $withoutOptions, 'note' => UnmatchedOptionRetry::NOTE];
        }

        // Second, and after the option retry because that one is the more specific diagnosis: the
        // words themselves may simply not be in the shop's keyword index. "gloves" found nothing in
        // a shop that sells the Commuter Glove — the storefront's own search box has the same gap —
        // and the model then told a shopper the shop carries no gloves. See RelaxedTermRetry.
        $relaxed = RelaxedTermRetry::search($gateway, $query, $scope, $trace);

        if ($relaxed !== null && $relaxed !== []) {
            return ['cards' => $relaxed, 'note' => RelaxedTermRetry::NOTE];
        }

        return ['cards' => [], 'note' => null];
    }
}
