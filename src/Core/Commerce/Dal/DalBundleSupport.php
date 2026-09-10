<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\ProductDefinition;

/**
 * Asks `ProductDefinition` itself whether Commercial's bundle association is registered.
 *
 * The field is added by an `EntityExtension`, so it is present exactly when the extension is —
 * which is what the criteria builder needs to know and the only thing this reports. Memoised
 * because the answer cannot change within a request and `getFields()` compiles a whole field
 * collection.
 */
final class DalBundleSupport implements BundleSupport
{
    private ?bool $available = null;

    public function __construct(
        private readonly ProductDefinition $products,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        // A definition this cannot interrogate is treated as not having the field, matching
        // DalVectorSupport's "a failure of any kind means unsupported": the alternative is letting
        // an exception out of the check that exists to prevent one.
        try {
            $this->available = $this->products->getFields()->has(DalBundleItems::ASSOCIATION);
        } catch (\Throwable) {
            $this->available = false;
        }

        return $this->available;
    }
}
