<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;

/**
 * Reads a Shopware property-option collection into group-keyed arrays.
 *
 * Split out of {@see DalProductCardMapper} (cyclomatic-complexity) rather than suppressed, and the
 * two methods share one rule worth stating once:
 *
 * **An option whose group is not resolved is dropped, never keyed by a guess.** An unloaded
 * `options.group` association yields a null group, and inventing a key there would hand
 * `VariantResolver` a group name this catalogue does not have — a fabricated key is worse than a
 * missing one, because the constraint would then appear to have been applied.
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

        foreach ($this->named($options) as [$group, $name]) {
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

        foreach ($this->named($properties) as [$group, $name]) {
            $mapped[$group][] = $name;
        }

        return $mapped;
    }

    /**
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function named(?PropertyGroupOptionCollection $options): \Generator
    {
        foreach ($options ?? [] as $option) {
            $group = $option->getGroup()?->getTranslation('name') ?? $option->getGroup()?->getName();
            $name = $option->getTranslation('name') ?? $option->getName();

            if (!\is_string($group) || !\is_string($name) || $group === '' || $name === '') {
                continue;
            }

            yield [$group, $name];
        }
    }
}
