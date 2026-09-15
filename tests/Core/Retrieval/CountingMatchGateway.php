<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CappedMatchCountReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * A gateway that answers a capped count and remembers how often it was asked.
 *
 * Its own file rather than an anonymous class, for the reason {@see RecordingTermGateway} is: a
 * factory method returning `CappedMatchCountReader` erases the double's own properties, and how many
 * counts were actually run is the whole point of {@see CappedMatchCountTest} — the saving is the
 * queries NOT made once one candidate has already answered "very many".
 */
final class CountingMatchGateway implements CappedMatchCountReader
{
    public int $calls = 0;

    public function __construct(
        private readonly int $trueCount,
    ) {}

    public function countMatches(ProductQuery $query, CatalogScope $scope): int
    {
        return $this->trueCount;
    }

    public function countMatchesUpTo(ProductQuery $query, CatalogScope $scope, int $cap): int
    {
        ++$this->calls;

        return min($this->trueCount, $cap);
    }
}
