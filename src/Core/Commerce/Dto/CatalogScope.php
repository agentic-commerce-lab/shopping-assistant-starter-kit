<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class CatalogScope
{
    /**
     * @param list<string> $includeCategoryIds
     * @param list<string> $excludeCategoryIds
     * @param list<string> $blockedProductIds
     * @param list<string> $blockedCategoryIds
     */
    public function __construct(
        public array $includeCategoryIds = [],
        public array $excludeCategoryIds = [],
        public array $blockedProductIds = [],
        public array $blockedCategoryIds = [],
        public int $minDescriptionWords = 0,
    ) {}
}
