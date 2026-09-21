<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\StockedFamilyLookup;

/**
 * Answers which families still have stock, and remembers how often it was asked.
 *
 * Its own file rather than an anonymous class inside each test, for the reason
 * {@see \Swag\AssistantStarterKit\Tests\Core\Retrieval\RecordingTermGateway} is: a method returning
 * `StockedFamilyLookup` erases the double's own properties, and `$lookup->calls` then reads as an
 * access on the interface. How often the shop was asked is half of what these tests assert — one
 * query per result rather than one per card is the difference between a correctness fix worth having
 * and one that is not.
 */
final class RecordingFamilyLookup implements StockedFamilyLookup
{
    public int $calls = 0;

    /** @param list<string> $withStock parent ids the shop still has something stocked under */
    public function __construct(
        private readonly array $withStock = [],
    ) {}

    public function familiesWithStock(array $parentIds, CatalogScope $scope): array
    {
        ++$this->calls;

        return array_values(array_intersect($parentIds, $this->withStock));
    }
}
