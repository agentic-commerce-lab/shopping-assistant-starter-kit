<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * The one bound both the model-facing tool shape ({@see ToolProductSummary}) and the storefront-facing
 * card payload ({@see \Swag\AssistantStarterKit\Controller\CardPayload}) apply to a single product's
 * `properties`, so a product with a sprawling attribute list cannot inflate either surface unevenly.
 *
 * Deliberately simpler than {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabularyBudget}: that
 * class bounds a whole catalogue's vocabulary block by character budget, because a facet set spans
 * every product at once. This bounds one product's own properties, where a flat group/value cap is
 * already enough — there is no rendered-text budget to fit.
 */
final class BoundedProperties
{
    private const MAX_GROUPS = 6;

    private const MAX_VALUES_PER_GROUP = 4;

    private function __construct() {}

    /**
     * @param array<string, list<string>> $properties
     *
     * @return array<string, list<string>>
     */
    public static function of(array $properties): array
    {
        $bounded = [];

        foreach (\array_slice($properties, 0, self::MAX_GROUPS, true) as $group => $values) {
            $bounded[$group] = \array_slice($values, 0, self::MAX_VALUES_PER_GROUP);
        }

        return $bounded;
    }
}
