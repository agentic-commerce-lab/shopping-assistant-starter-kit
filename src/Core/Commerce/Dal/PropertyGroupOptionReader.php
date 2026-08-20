<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;

/**
 * Reads a Shopware property-option collection into group-keyed arrays.
 *
 * Split out of {@see DalProductCardMapper} (cyclomatic-complexity) rather than suppressed.
 * Reading each option's group and name, and putting them in the catalogue's own order, is
 * {@see PropertyGroupOptionOrder}'s job for the same reason — including the rule that an
 * option with no resolved group is dropped rather than keyed by a guess.
 */
final readonly class PropertyGroupOptionReader
{
    /**
     * A variant's options: exactly one value per group, e.g. ['Colour' => 'Blue', 'Size' => 'M'].
     *
     * @return array<string, string>
     */
    public function singleValued(?PropertyGroupOptionCollection $options): array
    {
        $mapped = [];

        foreach (PropertyGroupOptionOrder::pairs($options) as [$group, $name]) {
            $mapped[$group] = $name;
        }

        return $mapped;
    }

    /**
     * A product's properties: several values per group, e.g. ['Material' => ['Merino', 'Nylon']].
     *
     * @return array<string, list<string>>
     */
    public function multiValued(?PropertyGroupOptionCollection $properties): array
    {
        $mapped = [];

        foreach (PropertyGroupOptionOrder::pairs($properties) as [$group, $name]) {
            $mapped[$group][] = $name;
        }

        return $mapped;
    }
}
