<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

/**
 * Turns blocked Dynamic Product Group ids into conditions a criteria can exclude.
 *
 * An interface for the same reason {@see BundleSupport} is one: {@see DalCriteriaBuilder} is
 * constructed with plain defaults in a dozen tests and in the eval harness, and neither has a
 * `product_stream` repository to hand. {@see NoStreamFilters} is what they get, and it is also the
 * honest answer for a shop where the feature is simply not configured.
 */
interface StreamFilters
{
    /**
     * @param list<string> $streamIds
     *
     * @return list<Filter> one condition per group that resolved to anything; groups that could not
     *                      be resolved are absent rather than represented by something that blocks
     *                      nothing, so the caller can tell "no groups" from "no usable groups"
     *                      by count alone
     */
    public function filtersFor(array $streamIds): array;
}
