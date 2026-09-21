<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

/**
 * What {@see DalCriteriaBuilder} uses when nothing wired a `product_stream` reader into it — the
 * same null-object shape {@see BundlesUnavailable} gives bundle support.
 *
 * It answers **nothing**, never "everything". A resolver that could not be reached must leave the
 * catalogue as it found it: the alternative reading, "block everything the merchant might have
 * meant", empties a shop on a misconfiguration.
 */
final class NoStreamFilters implements StreamFilters
{
    public function filtersFor(array $streamIds): array
    {
        return [];
    }
}
