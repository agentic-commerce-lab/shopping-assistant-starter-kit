<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

/**
 * Resolves a product id to a storefront URL.
 *
 * An interface rather than a router injected straight into
 * {@see DalProductCardMapper}: the mapper is the class that enforces
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard}'s allowlist, and it is worth
 * keeping unit-testable without a routing container so those assertions stay cheap to run.
 */
interface ProductUrlResolver
{
    public function urlFor(string $productId): string;
}
