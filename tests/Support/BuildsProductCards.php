<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Card lists for the tests that care about how many PRODUCTS a list represents rather than what is
 * on them.
 *
 * Shared rather than copied because two test classes decide one contract between them — see
 * {@see \Swag\AssistantStarterKit\Tests\Core\Tool\SearchResultCountsTest} and
 * {@see \Swag\AssistantStarterKit\Tests\Core\Tool\WithheldCountsProductsTest} — and because a
 * per-file copy of an eleven-argument constructor is how two tests start disagreeing about what a
 * standalone product is.
 */
trait BuildsProductCards
{
    /**
     * A minimal `products` array in the shape {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}
     * produces. Its contents are irrelevant to the counts; the declared shape is not.
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, bundle?: list<array{name: string, quantity?: int, optional?: true}>, soldOut?: true, available?: true, reasons?: list<string>}>
     */
    private static function summaries(): array
    {
        return [['id' => 'a', 'name' => 'Gravel Helmet', 'options' => ['Size' => 'M'], 'properties' => []]];
    }

    /**
     * `$count` cards, each its own standalone product.
     *
     * @return list<ProductCard>
     */
    private static function standalone(int $count): array
    {
        $cards = [];

        for ($i = 0; $i < $count; ++$i) {
            $cards[] = self::card('p' . $i, null);
        }

        return $cards;
    }

    /**
     * `$families` products with `$each` variants apiece — the shape
     * {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier} reduces to one card per product.
     *
     * @return list<ProductCard>
     */
    private static function variantsOf(int $families, int $each): array
    {
        $cards = [];

        for ($f = 0; $f < $families; ++$f) {
            for ($v = 0; $v < $each; ++$v) {
                $cards[] = self::card("f{$f}v{$v}", 'f' . $f);
            }
        }

        return $cards;
    }

    private static function card(string $id, ?string $parentId): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: 'Card',
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 5,
            stockSource: $parentId === null ? StockSource::Product : StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }
}
