<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Single-product lookup exposed to the model as a Symfony AI tool.
 *
 * Only orchestrates the injected services — {@see VariantResolver} owns every
 * decision about whether a selection narrows to exactly one sellable variant,
 * {@see BlocklistFilter} owns compliance removal. Returns a product id only;
 * the card itself is registered with {@see FactRenderer} for the controller
 * to render after the run.
 */
#[AsTool(
    name: 'get_product',
    description: 'Look up one product by id, optionally resolving a variant by its option '
    . 'values. Returns the product id only. Use this to answer questions about a '
    . 'specific size, colour or configuration.',
)]
final class GetProductTool
{
    // @mago-expect lint:excessive-parameter-list
    // Every parameter is one collaborator this method orchestrates without reimplementing;
    // the brief dictates this exact list, and the mandated test constructs it positionally
    // with these same six arguments, so the list cannot shrink without either duplicating a
    // collaborator's logic here or breaking that test.
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly VariantResolver $variantResolver,
        private readonly BlocklistFilter $blocklist,
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param string $productId The product id to look up.
     * @param ?array<int, array{option: string, group?: string}> $options Option
     *     selections to resolve a specific variant, e.g. [{"option": "Blue"},
     *     {"option": "M", "group": "Size"}]. Use this catalogue's own spelling for
     *     "group" exactly — it is matched case-sensitively. The "option" value is
     *     NOT matched case-sensitively: it is canonicalised against this catalogue's
     *     own facet values before resolution, so "blue" and "Blue" behave the same.
     *
     * @return array{
     *     products: list<array{id: string, name: string, options: array<string, string>}>,
     *     total: int,
     *     note?: string,
     * }
     */
    public function __invoke(string $productId, ?array $options = null): array
    {
        $productId = Guard::boundedString($productId, 64, 'product_id') ?? '';
        $selections = VariantSelectionGuard::fromRaw($options, 'options');

        // Canonicalise the option VALUE against the catalog's own spelling before
        // either resolution path below, both of which match case-sensitively
        // (FixtureVariantMatcher::matches()) — see Finding R46. Mirrors
        // QueryBuilder::build()'s use of the same resolver for SearchProductsTool
        // (Finding I1); this tool has no QueryBuildResult to carry a
        // canonicalSelections list, so it calls the resolver directly here, against
        // facets fetched straight from the gateway rather than through FacetProbe —
        // a single-product lookup does not warrant FacetProbe's per-scope cache.
        if ($selections !== []) {
            $facets = $this->gateway->facets($this->config->scope);
            $selections = array_map(
                static fn(VariantSelection $selection): VariantSelection => VariantSelectionFilterResolver::resolve(
                    $selection,
                    $facets,
                )->canonical,
                $selections,
            );
        }

        $card = $this->gateway->product($productId, $this->config->scope);

        // A product that carries variants is never itself an indexed sellable unit —
        // only its variants are — so its own id only resolves once selections narrow
        // it to one of them. Fall back to the gateway's variant resolution using the
        // given id as the parent id, still going through VariantResolver below for the
        // authoritative match, its de-duplication and its trace event.
        if ($card === null && $selections !== []) {
            $card = $this->gateway->resolveVariant($productId, $selections, $this->config->scope);
        }

        if ($card === null) {
            $this->trace->record('retrieve', ['hits' => 0, 'retainedIds' => []]);

            return [
                'products' => [],
                'total' => 0,
                'note' => 'No such product in this shop.',
            ];
        }

        $this->trace->record('retrieve', ['hits' => 1, 'retainedIds' => [$card->id]]);

        $cards = $this->variantResolver->resolve([$card], $selections, $this->config->scope);

        $filtered = $this->blocklist->apply($cards, $this->config->scope);
        $this->trace->record('blocklist.filter', [
            'stage' => 'post',
            'removedIds' => $filtered['removed'],
        ]);

        $survivors = $filtered['cards'];
        $this->renderer->registerRetrieved($survivors);

        $result = [
            // Same shape as search_products: the model must be able to confirm WHICH variant it
            // got back, which a bare id cannot tell it. See ToolProductSummary.
            'products' => ToolProductSummary::of($survivors),
            'total' => \count($survivors),
        ];

        if ($survivors === []) {
            $result['note'] = 'Product exists but is not available in this shop.';
        }

        return $result;
    }
}
