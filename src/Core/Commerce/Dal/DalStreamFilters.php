<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

/**
 * A merchant's Dynamic Product Group, as one condition a query can exclude.
 *
 * ## Why a group and not a list of ids
 *
 * The settings form has blocked a product at a time since it was written, and the pilot merchant's
 * catalogue has over 100,000 of them. Picking is not the only cost: every picked id travels into
 * `EqualsAnyFilter('id', …)` on **every chat message**, so a few thousand of them is a query of a
 * few hundred kilobytes per shopper turn.
 *
 * A group is resolved into its **conditions**, never into the products that match them. The database
 * does the matching, so a group covering ten products and one covering half the catalogue cost the
 * same. Resolving it to an id set would reproduce the exact problem one layer down, and is the one
 * shortcut not to take here.
 *
 * ## The exception handling is the feature, not defensive noise
 *
 * A merchant deletes a group and forgets the setting; a group is saved with no conditions yet. Both
 * throw, and both would otherwise take down **every product read in the shop** — the assistant would
 * answer nothing at all, for a stale id in a field nobody remembers filling in. A group that cannot
 * be resolved blocks nothing, which is the same direction the rest of this plugin fails in.
 *
 * An empty condition set is refused for the opposite reason. A group with no conditions matches every
 * product, so an empty `AndFilter` inside the caller's `NOT` would exclude **the entire catalogue**.
 * The two failures point opposite ways and both end here as "blocks nothing".
 *
 * ## Inheritance does the variant work, measured rather than assumed
 *
 * Measured 2026-09-21 against a 118,232-row shop: not one of its 14,494 variant rows carries its own
 * `product_manufacturer_id` — every one is NULL and inherited. Asked through
 * `sales_channel.product.repository`, a group condition `manufacturerId = X` nevertheless matched the
 * parent **and** its variants, because the sales-channel read resolves inheritance. So for the fields
 * a merchant actually groups by — manufacturer, properties, category, custom fields — blocking a
 * group removes the whole family without this class doing anything clever.
 *
 * ## `ProductStreamBuilderInterface`, not `AbstractProductStreamBuilder`
 *
 * 6.6 has no `AbstractProductStreamBuilder` — the abstract class, and with it `enrichCriteria()`,
 * arrived on the 6.7 line. What 6.6 offers is `ProductStreamBuilderInterface::buildFilters()`,
 * which hands the conditions back as a `list<Filter>` rather than writing them into a `Criteria`
 * the caller passes in. Same conditions, same exceptions (`EntityNotFoundException` for a deleted
 * group, `NoFilterException` for one with none), one less round trip through a throwaway
 * `Criteria`. The service id is identical and public on both versions, so `services.xml` is
 * untouched.
 *
 * `stock` is the exception and the one to warn about: it is a real per-row column, and a family
 * parent's value is its own rather than its children's sum. A group built on stock therefore hides
 * 259 of 2,900 families whose every variant is in stock, in the shop measured. That is why
 * `hideOutOfStockProducts` exists as its own setting with its own guard
 * ({@see DalDiscoveryFilters}) and why the field's help text sends merchants there instead.
 */
final class DalStreamFilters implements StreamFilters
{
    /** @var array<string, ?Filter> resolved once per request; null means "this one blocks nothing" */
    private array $resolved = [];

    public function __construct(
        private readonly ProductStreamBuilderInterface $builder,
    ) {}

    /**
     * @param list<string> $streamIds
     *
     * @return list<Filter> one condition per group that resolved to anything
     */
    public function filtersFor(array $streamIds): array
    {
        $filters = [];

        foreach ($streamIds as $streamId) {
            $filter = $this->resolved[$streamId] ??= $this->resolve($streamId);

            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * One group's conditions, ANDed. A group saying *"manufacturer is X and released before June"*
     * describes one set; handing both conditions back loose would let the caller OR them into its
     * exclusion list, and the group would then block everything by X **plus** everything released
     * before June.
     */
    private function resolve(string $streamId): ?Filter
    {
        try {
            $filters = $this->builder->buildFilters($streamId, Context::createDefaultContext());
        } catch (\Throwable) {
            // Deliberately every throwable, not the two documented ones: this runs on the path of
            // every product read, and the cost of a swallowed surprise is one group not blocking,
            // against a shop that answers nothing at all.
            return null;
        }

        return $filters === [] ? null : new AndFilter($filters);
    }
}
