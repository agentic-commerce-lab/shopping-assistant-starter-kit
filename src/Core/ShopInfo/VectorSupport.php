<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Whether this shop's database can store and compare the vectors shop-information retrieval needs.
 *
 * An interface so {@see ShopInfoAvailability} can be unit tested without a database, and so a shop on
 * a store this project has not shipped a bridge for can answer the question its own way — the same
 * reason `MarkerCategoryStore` and `CommerceGatewayInterface` are interfaces.
 */
interface VectorSupport
{
    public function isAvailable(): bool;

    /** What the shop actually runs, for a message that diagnoses rather than restates. */
    public function describe(): string;
}
