<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;

/**
 * A family lookup that answers from a map and counts how often each family was asked for.
 *
 * Its own file rather than an anonymous class, like {@see RecordingFamilyLookup} beside it: a method
 * returning `FamilyVariantLookup` erases the double's own properties, and one read per FAMILY rather
 * than one per CARD is the assertion these tests exist for.
 */
final class RecordingVariantLookup implements FamilyVariantLookup
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<ProductCard>> $byParent */
    public function __construct(
        private readonly array $byParent = [],
    ) {}

    public function variantsOf(string $parentId, CatalogScope $scope): array
    {
        $this->calls[$parentId] = ($this->calls[$parentId] ?? 0) + 1;

        return $this->byParent[$parentId] ?? [];
    }
}
