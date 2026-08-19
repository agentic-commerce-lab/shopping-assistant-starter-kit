<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;
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
    . 'Option group names, when given, must match this catalogue\'s own spelling '
    . 'exactly (for example "Colour", not "colour") — a group name that does not '
    . 'match a group this catalogue actually has is dropped rather than guessed at. '
    . 'Returns product ids only — the shop renders names, prices, stock and links. '
    . 'Never state a figure yourself; refer to products by id.',
)]
final class SearchProductsTool
{
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
     * @param ?array<int, array{option: string, group?: string}> $options Option
     *     selections to narrow to a specific variant, e.g. [{"option": "Blue"},
     *     {"option": "M", "group": "Size"}]. Use this catalogue's own spelling for
     *     "group" exactly — it is matched case-sensitively.
     * @param int $limit Maximum number of products to return (1-20).
     *
     * @return array{productIds: list<string>, total: int, note?: string}
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
        $limit = Guard::boundedInt($limit, 1, 20, 'limit');
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
        ]);

        // QueryBuilder::build() does not carry a limit — ShopperIntent has none — so the
        // guarded $limit argument is applied here, on top of the query it produced,
        // rather than being silently dropped on the floor.
        $query = new ProductQuery(
            term: $buildResult->query->term,
            filters: $buildResult->query->filters,
            limit: $limit,
            sort: $buildResult->query->sort,
        );

        // The full scope — including blockedProductIds/blockedCategoryIds — goes to
        // retrieval, not a stripped-down one: a well-behaved gateway should never even
        // fetch a blocked product, and that is strictly less exposure than fetching it and
        // relying on removal afterwards. BlocklistFilter below still runs unconditionally
        // as the second line of defence, for a gateway whose scope mapping is incomplete
        // (the future Shopware DAL implementation, mapping scope onto Store API filters,
        // plausibly will be one) and for VariantResolver::resolve()'s own
        // gateway->resolveVariant() call below, which applies no scope filtering at all.
        // With FixtureCommerceGateway, whose search() already fully honours the scope,
        // this second line is expected to record zero removals in ordinary operation —
        // an unfireable safety net is not a broken one; its primary control is holding.
        $cards = $this->gateway->search($query, $scope);
        $this->trace->record('retrieve', [
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn($card) => $card->id, $cards),
        ]);

        $cards = $this->variantResolver->resolve($cards, $intent->selections);

        $filtered = $this->blocklist->apply($cards, $scope);
        $this->trace->record('blocklist.filter', [
            'stage' => 'post',
            'removedIds' => $filtered['removed'],
        ]);

        $survivors = $filtered['cards'];
        $this->renderer->registerRetrieved($survivors);

        $result = [
            'productIds' => array_map(static fn($card) => $card->id, $survivors),
            'total' => \count($survivors),
        ];

        if ($survivors === []) {
            $result['note'] = 'No matching products in this shop.';
        }

        return $result;
    }
}
