<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Finds known catalogue attribute values — the shop's own closed vocabulary, the same
 * {@see FacetSet} {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary} already renders into
 * the prompt — that appear verbatim in a shopper-facing reply.
 *
 * Unlike {@see CurrencyFigureExtractor}, which recognises a price by its own shape (a currency token,
 * or two decimals), an attribute value has no structural marker: "Blue" is indistinguishable from an
 * ordinary word by shape alone. What makes this tractable is the closed universe instead — only a
 * literal, exact value from this turn's own facets is ever extracted, never an inferred or synonymous
 * one. A property value that also happens to be an ordinary English word can still produce a false
 * positive; accepted for v1 per the design spec, revisit if it proves noisy.
 *
 * **Only `properties.*` facets are scanned.** `FacetSet` also carries `categoryPath` (a real Terms
 * facet, not a product attribute — measured at 399 values on the fashion eval catalogue) and `price`
 * (a Range facet already excluded by requiring `properties.` prefix, belt-and-braces with the type
 * check below). Both `FixtureFacetBuilder` and the production `DalFacetReader` namespace true
 * property-group facets under this same `properties.` prefix — see either class's own docblock —
 * so filtering on it is the shop's own convention, not one invented here.
 */
final class PropertyClaimExtractor
{
    private const PROPERTY_FIELD_PREFIX = 'properties.';

    /**
     * @return list<string> known facet values found in the prose, in the shop's own casing, deduplicated
     */
    public function extract(string $prose, FacetSet $facets): array
    {
        $normalisedProse = ' ' . mb_strtolower($prose) . ' ';
        $found = [];

        foreach ($facets->facets as $facet) {
            if (FacetType::Terms !== $facet->type || !str_starts_with($facet->field, self::PROPERTY_FIELD_PREFIX)) {
                continue;
            }

            foreach ($facet->values as $value) {
                if ($value === '' || \in_array($value, $found, true)) {
                    continue;
                }

                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($value), '/') . '(?![\p{L}\p{N}])/u';

                if (preg_match($pattern, $normalisedProse) === 1) {
                    $found[] = $value;
                }
            }
        }

        return $found;
    }
}
