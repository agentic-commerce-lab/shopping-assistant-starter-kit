<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Retries a search without its option filters when those filters left nothing.
 *
 * **The failure this exists for.** A shopper asks *"is that bottle cage available in blue?"*
 * about a product the merchant never gave a colour. `Blue` is a real value in this
 * catalogue — other products have it — so the filter is APPLIED rather than dropped, and it
 * eliminates the only product that matched the words. The shopper got silence: no card, and
 * prose with nothing to ground it. Measured against `tests/Fixtures/catalog.json`:
 *
 * ```
 * bottle cage, no options      -> fx-017     applied=[]
 * bottle cage + [Colour,Blue]  -> (none)     applied=["properties.Colour"]
 * ```
 *
 * `VISION.md` names this exact case as the differentiator — *"turn 'your AI is bad' into
 * 'your products are missing attribute X'"* — and silence is the one answer that cannot do
 * that. So retrieval runs a second time without the option filters, and the model is told,
 * in the tool result, that the option is **not recorded** rather than that the product is
 * unavailable. Those are different claims and only the first one is true.
 *
 * **Only option filters are dropped.** A price bound or a brand the shopper stated is a
 * constraint they would notice being ignored, so `$selectionFilters` carries exactly the
 * clauses that came from option selections (see {@see QueryBuildResult::$selectionFilters})
 * and nothing else is touched. Verified against the fixture catalogue: `"bottle cage"` with a
 * `priceMax` of 5.00 and `Colour=Blue` still returns nothing after the retry, because only
 * the colour clause is removed.
 *
 * **Two situations reach here, and the note does not guess between them.** A value in a group
 * this catalogue HAS is applied even when no product carries that value —
 * `VariantSelectionFilterResolver::resolveGrouped()` falls back to the model's own spelling
 * when the facet holds no match — so `Colour=Chartreuse` narrows to nothing and lands here
 * just as a colourless product does. A group-less value the catalogue does not have anywhere
 * is dropped by {@see QueryBuilder} instead, and never narrowed the first search at all. See
 * {@see self::NOTE}.
 *
 * **It cannot widen what the blocklist or the scope allow.** The retry passes the same
 * {@see CatalogScope} to the same gateway call, and every stage after it — variant
 * resolution, the blocklist, narrowing, {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 * registration — runs over the result exactly as it would for a first-pass search.
 */
final class UnmatchedOptionRetry
{
    /**
     * What the model is told.
     *
     * It deliberately does NOT assert why the option did not match, because two different
     * situations reach here and only the products themselves distinguish them:
     *
     * - the product has no such option group at all — *"the shop records no colour for this
     *   cage"*;
     * - the product has the group but not that value — *"the jersey comes in blue and black,
     *   not chartreuse"*.
     *
     * An earlier draft asserted the first unconditionally. That would have made the assistant
     * state something false about the second — the exact class of claim this project exists to
     * prevent, in the one code path added to make it more honest. Every product in the result
     * carries its own recorded options (see
     * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}), so the model is
     * pointed at that data rather than handed a conclusion.
     *
     * The prohibitions stay absolute:
     * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit} covers currency figures,
     * not option values, so this wording is the only thing standing between "no colour is
     * recorded" and "yes, here is the blue one".
     */
    public const NOTE =
        'No product matches those option values. The products below match the '
            . 'rest of the query, and each one lists the options this shop actually records for '
            . 'it. Compare those with what the shopper asked for: say either that the shop '
            . 'records no such option for the product, or that it is not offered in the value '
            . 'asked for — whichever its listed options show. Do NOT say the product is '
            . 'unavailable, and do NOT attribute to it any option value it does not list.';

    private function __construct() {}

    /**
     * @param list<FilterClause> $selectionFilters
     *
     * @return list<ProductCard>|null null when no retry applies — either no option filter was
     *                                applied in the first place, or dropping them would change
     *                                nothing
     */
    public static function search(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        array $selectionFilters,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): ?array {
        if ($selectionFilters === []) {
            return null;
        }

        $kept = array_values(array_filter(
            $query->filters,
            static fn(FilterClause $filter): bool => !\in_array($filter, $selectionFilters, strict: true),
        ));

        if (\count($kept) === \count($query->filters)) {
            return null;
        }

        $cards = $gateway->search(
            new ProductQuery(
                term: $query->term,
                filters: $kept,
                limit: $query->limit,
                sort: $query->sort,
                candidateLimit: $query->candidateLimit,
                // Carried, not dropped: this retry relaxes ONE thing, and the shopper's location
                // is not it. Leaving the category out here would abandon the aisle silently, with
                // nothing in the trace saying so — which is what `retrieve.without_category`
                // exists to say. In the shipped order SearchProductsTool has already dropped the
                // category before this runs, so in practice it is null; carrying it keeps this
                // class correct on its own terms rather than relying on that ordering.
                categoryId: $query->categoryId,
            ),
            $scope,
        );

        $trace->record('retrieve.without_options', [
            'droppedFields' => array_map(static fn(FilterClause $f): string => $f->field, $selectionFilters),
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
        ]);

        return $cards;
    }
}
