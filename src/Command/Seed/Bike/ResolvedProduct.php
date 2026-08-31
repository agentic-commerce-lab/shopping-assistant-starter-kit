<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything one product needed looked up in the shop, all of it present.
 *
 * **Its existence is the guarantee.** There is no partially-resolved instance:
 * {@see ProductReferences::resolveAll()} either returns one of these with every field filled or
 * returns null, so a caller holding one does not have to re-check anything. That is what let
 * {@see BikeSeedPlan::product()} drop a four-clause guard whose clauses each carried their own null.
 */
final readonly class ResolvedProduct
{
    /** @param list<array{id: string}> $properties */
    public function __construct(
        public string $categoryId,
        public string $manufacturerId,
        public array $properties,
    ) {}
}
