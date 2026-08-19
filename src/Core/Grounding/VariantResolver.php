<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * VariantResolver is the last line of defence against the failure that cancels
 * orders: reporting a parent product's aggregate stock in answer to a variant
 * question ("do you have the blue jersey in M?").
 *
 * A shopper-facing selection ("Blue", "M") only ever identifies a sellable unit
 * through {@see CommerceGatewayInterface::resolveVariant()}, which itself never
 * guesses — it returns null unless the selections narrow to exactly one variant.
 * This class trusts that contract completely: a non-null result *replaces* the
 * card with the variant's own price, stock and {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Variant};
 * a null result leaves the original card untouched, aggregate stock and all,
 * rather than fabricating a variant or silently narrowing to a guess.
 *
 * `search()` returns one sellable unit per variant, so a search for a product
 * family with several variants hands this class several cards that all share
 * the same `parentId`. Resolving the same selections against the same parent
 * necessarily yields the same answer for every one of them — so without
 * de-duplication a single matched variant would be reported once per input
 * card, showing the shopper the same product listed several times. Only the
 * first occurrence of a resulting id is kept, in input order. This applies
 * only on the resolution path: with `$selections === []` the cards pass
 * through untouched, duplicates and all, since a caller legitimately holding
 * two identical ids is not this class's problem.
 */
final class VariantResolver
{
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly TraceRecorder $trace,
    ) {}

    /**
     * @param list<ProductCard>      $cards
     * @param list<VariantSelection> $selections
     *
     * @return list<ProductCard>
     */
    public function resolve(array $cards, array $selections, CatalogScope $scope): array
    {
        if ($selections === []) {
            return $cards;
        }

        $resolved = [];
        $attempts = [];
        $seenIds = [];

        foreach ($cards as $card) {
            $parentId = $card->parentId ?? $card->id;
            $variant = $this->gateway->resolveVariant($parentId, $selections, $scope);

            $attempts[] = [
                'parentId' => $parentId,
                'variantId' => $variant?->id,
                'priceRefetched' => null !== $variant,
                'stockRefetched' => null !== $variant,
            ];

            $result = $variant ?? $card;

            if (array_key_exists($result->id, $seenIds)) {
                continue;
            }

            $seenIds[$result->id] = true;
            $resolved[] = $result;
        }

        $this->trace->record('variant.resolve', ['attempts' => $attempts]);

        return $resolved;
    }
}
