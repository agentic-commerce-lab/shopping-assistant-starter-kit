<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

final readonly class SeedReport
{
    public function __construct(
        public int $categoryCount,
        public int $propertyGroupCount,
        public int $productCount,
        public int $sellableUnits,
    ) {}
}
