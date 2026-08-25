<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Resolves a list of product ids to cards, in one round trip where the gateway allows it.
 *
 * This is what the cards endpoint needs: a returning shopper reopens the widget, the stored
 * conversation holds ids rather than prices, and every id has to become a current card — current
 * because a price or a stock figure from last week is exactly what this project refuses to show.
 *
 * Phase B measured the loop it replaces: 12 ids, 12 lookups, 143.8 ms, ~12 ms each.
 *
 * Lives here rather than in the controller so it can be tested without booting Shopware, and so the
 * fallback decision is written down once instead of at every call site.
 */
final class CardResolver
{
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
    ) {}

    /**
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    public function resolve(array $productIds, CatalogScope $scope): array
    {
        if ([] === $productIds) {
            return [];
        }

        $gateway = $this->gateway;
        $cards = $gateway instanceof BatchProductLookup
            ? $gateway->products($productIds, $scope)
            : $this->oneByOne($productIds, $scope);

        return self::inRequestedOrder($cards, $productIds);
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    private function oneByOne(array $productIds, CatalogScope $scope): array
    {
        $cards = [];

        foreach ($productIds as $id) {
            $card = $this->gateway->product($id, $scope);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * The order the caller asked for, with unresolved ids dropped rather than left as holes.
     *
     * A batched query returns rows in whatever order the engine chose, and the widget renders the row
     * in the order it receives — so without this, batching would silently reshuffle a shopper's cards
     * on every history restore. The loop this replaces preserved order for free, which is precisely
     * how a change like this regresses without anyone noticing.
     *
     * @param list<ProductCard> $cards
     * @param list<string>      $productIds
     *
     * @return list<ProductCard>
     */
    private static function inRequestedOrder(array $cards, array $productIds): array
    {
        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->id] = $card;
        }

        $ordered = [];
        foreach ($productIds as $id) {
            $card = $byId[$id] ?? null;

            if ($card !== null) {
                $ordered[] = $card;
            }
        }

        return $ordered;
    }
}
