<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;
use Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Read-only catalogue search exposed to the model as a Symfony AI tool.
 *
 * This method only orchestrates the injected services — it never reimplements
 * facet probing, query building, variant resolution or blocklist filtering
 * itself, which is what lets a third-party tool built the same way inherit the
 * same grounding guarantees. It returns product ids only: cards are registered
 * with {@see FactRenderer} for the controller to render after the run, never
 * serialised into the tool result the model sees.
 */
#[AsTool(
    name: 'search_products',
    description: 'Search this shop\'s catalogue. Price limits are honoured exactly. '
    . 'ALWAYS pass every option value the shopper named — colour, size and so on — in this '
    . 'same call via "options". Doing so resolves the exact variant in one step and returns '
    . 'its own stock and price; leaving them out returns the whole product family instead, '
    . 'and identifying the right member afterwards wastes the turn\'s tool-call budget. '
    . 'Each "options" entry is a [group, option] pair in that order, for example '
    . '"options": [["Colour", "Blue"], ["Size", "M"]]. A bare option value on its own — '
    . '"options": ["Blue", "M"] — also works when you do not know its group. '
    . 'Option group names, when given, must match this catalogue\'s own spelling '
    . 'exactly (for example "Colour", not "colour") — a group name that does not '
    . 'match a group this catalogue actually has is dropped rather than guessed at. '
    . 'Returns each product\'s id, name and option values, so you can tell them apart. '
    . 'It returns NO prices, stock or availability: the shop renders those. '
    . 'Never state a figure yourself.',
)]
final class SearchProductsTool
{
    /**
     * How much wider than the model's `limit` the retrieval window is, and its bounds.
     *
     * Retrieval, ranking and truncation used to happen together inside the gateway, so a
     * narrow `limit` decided the answer before variant resolution ever ran. Now the gateway
     * retrieves this wider window, resolution and the blocklist run over all of it, and the
     * result is narrowed to the model's own `limit` afterwards — the ordering
     * ARCHITECTURE.md's lifecycle table always claimed.
     *
     * The floor matters more than the multiplier: a family with several variants must fit
     * inside the window whole, or ranking's in-stock bias can still hide the sold-out unit.
     * The ceiling bounds retrieval cost, since these are id-only reads.
     */
    private const CANDIDATE_MULTIPLIER = 4;

    private const MIN_CANDIDATES = 20;

    private const MAX_CANDIDATES = 50;

    // @mago-expect lint:excessive-parameter-list
    // Every parameter is one collaborator this method orchestrates without reimplementing;
    // the brief dictates this exact list, and the mandated test constructs it positionally
    // with these same eight arguments, so the list cannot shrink without either duplicating
    // a collaborator's logic here or breaking that test.
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly FacetProbe $facetProbe,
        private readonly QueryBuilder $queryBuilder,
        private readonly VariantResolver $variantResolver,
        private readonly BlocklistFilter $blocklist,
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param ?string $term    Free-text search term, e.g. "water bottle".
     * @param ?float  $priceMax Maximum price, inclusive, in the shop's currency.
     * @param ?float  $priceMin Minimum price, inclusive, in the shop's currency.
     * @param ?string $brand   Brand name to filter by.
     * @param ?array<array-key, array<array-key, string>|string> $options Option selections narrowing to one variant, each a [group, option] pair such as [["Colour", "Blue"], ["Size", "M"]]. Group names use this catalogue's own spelling; a bare option value on its own also works.
     * @param int $limit Maximum number of products to return (1-20).
     *
     * @return array{
     *     products: list<array{id: string, name: string, options: array<string, string>}>,
     *     total: int,
     *     note?: string,
     * }
     */
    // @mago-expect lint:excessive-parameter-list
    // #[AsTool] derives the model-facing JSON Schema from this exact signature by reflection
    // (see the class docblock); collapsing these into a DTO would either change the schema the
    // model sees or break the mandated test, which calls this method with these named scalar
    // arguments directly.
    public function __invoke(
        ?string $term = null,
        ?float $priceMax = null,
        ?float $priceMin = null,
        ?string $brand = null,
        ?array $options = null,
        int $limit = 10,
    ): array {
        $term = Guard::boundedString($term, 200, 'term');
        $brand = Guard::boundedString($brand, 120, 'brand');
        $requestedLimit = Guard::boundedInt($limit, 1, 20, 'limit');

        // The model's `limit` is honoured exactly, and the ceiling stays a rejection: 20
        // bounds context size and cost, and a model asking for more is asking for something
        // it may not have.
        //
        // What used to be coerced here was the FLOOR, because retrieval and truncation were
        // the same step: a limit of 1 made the answer a function of retrieval ranking rather
        // than of the shopper's question, and nothing downstream could repair it —
        // VariantResolver cannot disambiguate a set of one, and the blocklist only removes.
        // Ranking's in-stock bias sorts a sold-out unit LAST, so "do you have the blue jersey
        // in M?" with limit 1 returned the blue L that happens to be in stock, and the
        // grounding pipeline then rendered a real price for the variant nobody asked about.
        //
        // The two concerns are now separate rather than traded off: retrieval reads the wider
        // candidate window below, and narrowing to the model's own limit happens after
        // resolution and the blocklist have run over all of it. So `limit` no longer needs
        // coercing, and the shopper's bound is no longer silently ignored.
        $candidateLimit = min(self::MAX_CANDIDATES, max(
            $requestedLimit * self::CANDIDATE_MULTIPLIER,
            self::MIN_CANDIDATES,
        ));
        $selections = VariantSelectionGuard::fromRaw($options, 'options');

        $intent = new ShopperIntent(
            term: $term,
            priceMax: $priceMax,
            priceMin: $priceMin,
            brand: $brand,
            selections: $selections,
        );

        $this->trace->record('understand', [
            'term' => $intent->term,
            'priceMax' => $intent->priceMax,
            'priceMin' => $intent->priceMin,
            'brand' => $intent->brand,
            'selectionCount' => \count($intent->selections),
            'source' => 'tool_arguments',
        ]);

        $scope = $this->config->scope;
        $facets = $this->facetProbe->probe($scope);

        $buildResult = $this->queryBuilder->build($intent, $facets);
        $this->trace->record('query.build', [
            'filtersApplied' => array_map(static fn($filter) => $filter->field, $buildResult->query->filters),
            'filtersDropped' => $buildResult->droppedFields,
            'searchTerm' => $buildResult->query->term,
            'limitRequested' => $requestedLimit,
            'candidateLimit' => $candidateLimit,
        ]);

        // QueryBuilder::build() does not carry a limit — ShopperIntent has none — so the
        // guarded limit and the candidate window are applied here, on top of the query it
        // produced, rather than being silently dropped on the floor.
        $query = new ProductQuery(
            term: $buildResult->query->term,
            filters: $buildResult->query->filters,
            limit: $requestedLimit,
            sort: $buildResult->query->sort,
            candidateLimit: $candidateLimit,
        );

        // The full scope — including blockedProductIds/blockedCategoryIds — goes to
        // retrieval, not a stripped-down one: a well-behaved gateway should never even
        // fetch a blocked product, and that is strictly less exposure than fetching it and
        // relying on removal afterwards. BlocklistFilter below still runs unconditionally
        // as the second line of defence, for a gateway whose scope mapping is incomplete
        // (the future Shopware DAL implementation, mapping scope onto Store API filters,
        // plausibly will be one). VariantResolver::resolve()'s own gateway->resolveVariant()
        // call below now also takes this same scope (Finding C1's seam change), but
        // whether an implementation actually enforces it there is its own choice — see
        // CommerceGatewayInterface::product()'s docblock — so this is not redundant.
        // With FixtureCommerceGateway, whose search() already fully honours the scope,
        // this second line is expected to record zero removals in ordinary operation —
        // an unfireable safety net is not a broken one; its primary control is holding.
        $cards = $this->gateway->search($query, $scope);
        $this->trace->record('retrieve', [
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn($card) => $card->id, $cards),
            'candidateLimit' => $candidateLimit,
        ]);

        // An applied option filter that eliminated everything is the "your products are
        // missing attribute X" case, not the "we do not sell it" case. See
        // UnmatchedOptionRetry for the measurement and for why silence was the wrong answer.
        $optionNote = null;
        if ($cards === []) {
            $withoutOptions = UnmatchedOptionRetry::search(
                $this->gateway,
                $query,
                $buildResult->selectionFilters,
                $scope,
                $this->trace,
            );

            if ($withoutOptions !== null && $withoutOptions !== []) {
                $cards = $withoutOptions;
                $optionNote = UnmatchedOptionRetry::NOTE;
            }
        }

        // Canonical selections, not $intent->selections: QueryBuilder already resolved
        // each one against the catalog's own spelling — see
        // VariantSelectionFilterResolver's docblock (Finding I1). Handing VariantResolver
        // the raw, model-cased selections instead would make gateway->resolveVariant()'s
        // case-sensitive matching fail exactly when the model's casing differs from the
        // catalog's — the retrieval filter above would already have narrowed correctly
        // while variant resolution silently did not.
        $cards = $this->variantResolver->resolve($cards, $buildResult->canonicalSelections, $scope);

        $filtered = $this->blocklist->apply($cards, $scope);
        $this->trace->record('blocklist.filter', [
            'stage' => 'post',
            'removedIds' => $filtered['removed'],
        ]);

        // **After the blocklist, deliberately, not before it.** The blocklist must see everything
        // retrieval and resolution produced, because `BlocklistSurvivors` diffs `retrieve`'s
        // retained ids against this stage's removals: a card taken out upstream would still count
        // as retained, never appear as removed, and so read as a survivor that leaked. A redundancy
        // narrowing must not be able to forge that.
        //
        // Shopware's search returns a family parent alongside its children, so "what bib shorts do
        // you sell?" came back as Black/L, Black/M and then the parent again — an unbuyable
        // aggregate and its disclosure, beside the two rows that had already answered the question.
        // See RedundantParentFilter for why only a superseded parent goes.
        $survivors = RedundantParentFilter::apply($filtered['cards']);
        $supersededParents = \count($filtered['cards']) - \count($survivors);

        // Narrowing happens HERE, not in retrieval. Everything above needed the full
        // candidate window to be correct — VariantResolver cannot disambiguate a set of
        // one — and nothing below can recover a unit that retrieval already dropped.
        $returned = \array_slice($survivors, offset: 0, length: $requestedLimit);

        // Recorded rather than silent: a bounded result that nobody wrote down reads as
        // complete coverage. This is its own stage because `retrieve` keeps meaning "what
        // retrieval returned" — BlocklistSurvivors diffs that set against the blocklist's
        // removals, and narrowing must not quietly shrink what that check sees.
        $this->trace->record('retrieve.narrow', [
            'candidateLimit' => $candidateLimit,
            'returnLimit' => $requestedLimit,
            'survivors' => \count($survivors),
            'supersededParents' => $supersededParents,
            'truncated' => \count($survivors) - \count($returned),
            'returnedIds' => array_map(static fn($card) => $card->id, $returned),
        ]);

        // The narrowed set, never $survivors: FactRenderer treats the last registered set
        // as the authority on what the model saw, so registering cards the model never
        // received would widen what counts as "not invented" and reopen R47's gap.
        $this->renderer->registerRetrieved($returned);

        $result = [
            // id + name + options, never a figure — see ToolProductSummary for why bare ids made
            // variant identification cost one tool call per candidate.
            'products' => ToolProductSummary::of($returned),
            'total' => \count($returned),
        ];

        if ($returned === []) {
            $result['note'] = 'No matching products in this shop.';
        } elseif ($optionNote !== null) {
            $result['note'] = $optionNote;
        }

        return $result;
    }
}
