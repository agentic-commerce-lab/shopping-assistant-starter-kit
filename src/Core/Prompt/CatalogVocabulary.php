<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Renders a {@see FacetSet} into a bounded, model-facing prompt block: the shop's own
 * search vocabulary (`Size: M, L, XL`, `Colour: Blue, Black`, …), nothing else.
 *
 * This is the first thing this project puts in front of the model that is shop DATA
 * rather than shop RULES, which is exactly why every safeguard here is deliberate:
 *
 * - Only {@see FacetType::Terms} facets render. A {@see FacetType::Range} facet's
 *   `min`/`max` are numbers (price, weight), and putting a number in front of the
 *   model is exactly the fabrication surface {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 *   exists to prevent — so range facets are skipped entirely, not summarised.
 * - The heading is load-bearing safety text, not decoration: it tells the model this
 *   list is a *vocabulary* (which spellings this catalogue matches), never an
 *   *inventory* (which products exist, or what any one product has). Without it, a
 *   model could answer "does the bottle cage come in blue?" straight from a
 *   catalogue-wide `Colour: Blue, Black` line, without ever calling a tool — see
 *   the `vocabulary_not_inventory` journey.
 * - Three independent bounds keep the block small and cheap: at most 25 values per
 *   field, at most 30 fields, and a 1500-character budget for the whole rendered
 *   block (heading included). {@see CatalogVocabularyBudget} enforces all three,
 *   values before whole fields at every stage.
 * - Any trimming — value cap, field cap, or budget cut — appends one disclosure
 *   line so the model is told the list may be incomplete, rather than treating
 *   silence as proof a value does not exist.
 *
 * Stateless and collaborator-free by design: this class (and the budget it delegates
 * to) only ever see the {@see FacetSet} handed to {@see self::render()}, nothing else
 * from the request.
 *
 * The bound-enforcement itself lives in {@see CatalogVocabularyBudget}, not here, purely
 * to keep this class's own aggregate cyclomatic complexity under this project's
 * threshold — the same reasoning documented on {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt}
 * for splitting out of {@see \Swag\AssistantStarterKit\Eval\JourneyRunner}.
 */
final class CatalogVocabulary
{
    private const HEADING = <<<'PROMPT'
        Words this shop uses. When you search, use these spellings — they are the only spellings this
        catalogue matches, so map the shopper's words onto them (a shopper saying "medium" means the
        value "M" if that is what this list shows).

        This list is a search vocabulary, not an inventory. It does not say which products exist, and
        it does not say what any single product has. Never answer a question about a product from this
        list — always call a tool and let the shop answer.
        PROMPT;

    private const SHORTENED_NOTE = '(this list is shortened — a word missing from it does not mean the shop lacks it)';

    private function __construct() {}

    public static function render(FacetSet $facets): string
    {
        return self::renderWithStats($facets)['text'];
    }

    /**
     * Same render, plus the counts {@see \Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory}
     * needs for its `vocabulary.render` trace event — computed from the same pass
     * rather than re-derived by parsing the rendered string back apart.
     *
     * @return array{text: string, fieldCount: int, valueCount: int, truncated: bool}
     */
    public static function renderWithStats(FacetSet $facets): array
    {
        $fields = self::termsFields($facets);

        if ([] === $fields) {
            return ['text' => '', 'fieldCount' => 0, 'valueCount' => 0, 'truncated' => false];
        }

        return CatalogVocabularyBudget::fit($fields, self::HEADING, self::SHORTENED_NOTE);
    }

    /**
     * @return list<array{field: string, values: list<string>}>
     */
    private static function termsFields(FacetSet $facets): array
    {
        $termsFacets = array_filter(
            $facets->facets,
            static fn(Facet $facet): bool => FacetType::Terms === $facet->type && [] !== $facet->values,
        );

        return array_values(array_map(static fn(Facet $facet): array => [
            'field' => $facet->field,
            'values' => $facet->values,
        ], $termsFacets));
    }
}
