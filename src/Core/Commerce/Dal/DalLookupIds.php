<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The product ids the DAL can actually look up.
 *
 * **A parse check, and the boundary it belongs to.** `EqualsFilter('id', …)` throws
 * `InvalidUuidException` on anything that is not 32 hex characters, and that exception is not one of
 * the three shapes {@see \Swag\AssistantStarterKit\Core\Agent\MalformedToolArgumentRejection}
 * converts into a retryable note — so it propagated as a `ToolExecutionException` and ended the
 * whole turn. Measured on staging 2026-09-10: asked to compare two products with no prior search,
 * the model passed their **names**, and the shopper read *"Sorry — I could not finish that just
 * now."*
 *
 * **Not a `Guard` in the tools, deliberately.** Being a UUID is a property of this gateway, not of
 * the tool contract: {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway} runs the
 * eval and journey suites on ids like `fx-017`, and a UUID check at the tool boundary would reject
 * every one of them. Here the answer is the one this boundary already gives for an id it cannot
 * resolve — nothing — so all three id-taking tools reach their existing "No such product in this
 * shop." path.
 *
 * It says nothing about whether a product exists. A well-formed id for a product that was deleted is
 * usable and simply returns no row, which is the repository's answer to give.
 */
final readonly class DalLookupIds
{
    /**
     * The id, or null when the DAL could not parse it.
     */
    public static function one(string $productId): ?string
    {
        return Uuid::isValid($productId) ? $productId : null;
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string> reindexed, because `EqualsAnyFilter` and the callers' own
     *                      `list<string>` contracts do not accept a filtered array's holes
     */
    public static function usable(array $productIds): array
    {
        return array_values(array_filter($productIds, static fn(string $id): bool => Uuid::isValid($id)));
    }
}
