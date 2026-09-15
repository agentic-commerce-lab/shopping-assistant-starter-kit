<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * How many of a product's property values did NOT reach the model, per group.
 *
 * ## The failure this exists for
 *
 * {@see BoundedProperties} caps a product at six groups and four values each, and until now it did
 * so silently: the model received four values and had no way to tell them from a complete list.
 *
 * **Measured 2026-09-14 against a real Shopware.** A brake pad carried fifteen vehicle models and
 * twelve model years as properties. The model was handed `FLHR, FLHRC, FLHRXS, FLHT` and
 * `2006, 2007, 2008, 2009`. Asked *"passt der Bremsbelag an meine Harley FLHRXS, Baujahr 2008?"* it
 * answered *"Ja, der Bremsbelag passt"* — unhedged, about a brake part. The FLHRXS was built from
 * 2021; that pad does not fit it. Nothing in the tool result was false. The model simply could not
 * see that it was looking at a quarter of the list, so it reasoned over it as though it were whole.
 *
 * A cap is the right call — a product with thousands of values cannot be handed over intact, and
 * {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabularyBudget} makes the same trade for the
 * catalogue. What was wrong was doing it without saying so. The same shape as `withheld` on a search
 * result ({@see SearchResultCounts}): the count the model needs is not the values, it is that values
 * exist which it has not been shown.
 *
 * ## Why a count and not a sentinel value
 *
 * Appending something like `"+11 weitere"` to the value list would put a string into `properties`
 * that no product has, and {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit} audits prose
 * claims against exactly those values. A separate key cannot be mistaken for a property.
 *
 * ## Model-facing only
 *
 * {@see \Swag\AssistantStarterKit\Controller\CardPayload} bounds the same map and deliberately does
 * not carry this: a card is a summary next to a "View product" link that leads to the full list,
 * and a shopper reading four of fifteen sizes is not being misled the way a model reasoning over
 * them is.
 */
final class PropertiesWithheld
{
    private function __construct() {}

    /**
     * The fragment to merge into a product summary, or `[]` when the model saw everything.
     *
     * Counted by DIFFING against {@see BoundedProperties::of()} rather than by reapplying its
     * limits, so the two cannot drift apart when a cap changes. A group dropped whole reports all
     * of its values, which is the honest number: none of them arrived.
     *
     * @param array<string, list<string>> $properties the product's full property map
     *
     * @return array{propertiesWithheld?: array<string, int>}
     */
    public static function keyFor(array $properties): array
    {
        $shown = BoundedProperties::of($properties);
        $withheld = [];

        foreach ($properties as $group => $values) {
            $missing = \count($values) - \count($shown[$group] ?? []);

            if ($missing > 0) {
                $withheld[$group] = $missing;
            }
        }

        return $withheld === [] ? [] : ['propertiesWithheld' => $withheld];
    }
}
