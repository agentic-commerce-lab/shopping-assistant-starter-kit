<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;

/**
 * Translates a {@see FilterClause} — whose field name is a LOGICAL name from the facet set,
 * not a DAL path — into a DAL {@see Filter}.
 *
 * This class exists because the two are genuinely different vocabularies, and conflating them
 * fails in the worst possible way. `QueryBuilder` guarantees that a clause's field came from
 * the catalogue's own facet set (that is the project's central "the model never supplies a
 * field name" guarantee), and those facets are named `price` and `properties.<Group>` to match
 * what `FixtureCommerceGateway` offers. None of those is a DAL field path. Handing
 * `properties.Colour` straight to an `EqualsFilter` produces a query on a field that does not
 * exist.
 *
 * Exactly three logical fields can arrive, because exactly three resolvers can emit one:
 *
 * | Logical field | Emitted by | DAL translation |
 * |---|---|---|
 * | `price` | `PriceFilterResolver` | `RangeFilter('price', …)` |
 * | `properties.Manufacturer` | `BrandFilterResolver` | `EqualsFilter('manufacturer.name', …)` |
 * | `properties.<Group>` | `VariantSelectionFilterResolver` | see below |
 *
 * A variant selection matches **either** association, because Shopware splits what a shopper
 * experiences as one thing: a variant's distinguishing values live in `options`, other
 * filterable values in `properties`. Both halves of each pair are ANDed so that the group and
 * the value must come from the same joined row — otherwise "Colour = M" would match a product
 * that merely has a Colour group and an M somewhere else.
 *
 * An unrecognised field throws rather than being silently dropped or passed through. A
 * pass-through would produce an invalid-field DAL error at query time, far from its cause; a
 * silent drop would widen the search behind a trace that claims the filter was applied — and
 * ruling R20 was exactly that failure, found only by a live run.
 */
final readonly class DalFilterTranslator
{
    public const MANUFACTURER_FIELD = 'properties.Manufacturer';

    public const PRICE_FIELD = 'price';

    public function __construct(
        private DalRangeBounds $bounds = new DalRangeBounds(),
    ) {}

    public function translate(FilterClause $clause): Filter
    {
        if ($clause->field === self::PRICE_FIELD) {
            return new RangeFilter(self::PRICE_FIELD, $this->bounds->of($clause->value));
        }

        if ($clause->field === self::MANUFACTURER_FIELD) {
            return new EqualsFilter('manufacturer.name', $this->scalar($clause->value));
        }

        if (str_starts_with($clause->field, DalFacetReader::GROUP_PREFIX)) {
            return $this->groupValue(
                substr($clause->field, \strlen(DalFacetReader::GROUP_PREFIX)),
                $this->scalar($clause->value),
                $clause->operator,
            );
        }

        throw new \InvalidArgumentException(\sprintf(
            'No DAL translation for filter field "%s". Only "%s", "%s" and "%s<Group>" are known, '
            . 'and every clause field originates in the catalogue facet set.',
            $clause->field,
            self::PRICE_FIELD,
            self::MANUFACTURER_FIELD,
            DalFacetReader::GROUP_PREFIX,
        ));
    }

    private function groupValue(string $group, string $value, FilterOperator $operator): Filter
    {
        $pairs = [];

        foreach (['properties', 'options'] as $association) {
            $pairs[] = new AndFilter([
                new EqualsFilter($association . '.group.name', $group),
                $operator === FilterOperator::Contains
                    ? new ContainsFilter($association . '.name', $value)
                    : new EqualsFilter($association . '.name', $value),
            ]);
        }

        return new OrFilter($pairs);
    }

    private function scalar(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('An equals or contains filter needs a string value.');
        }

        return $value;
    }
}
