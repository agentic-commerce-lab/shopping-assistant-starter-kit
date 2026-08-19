<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * Shared AssistantTurn builders for the Eval\Assertion test classes under
 * tests/Eval/Assertion/. None of those tests drive the real agent pipeline — every
 * assertion is defined to read the trace (or the renderer-computed unbacked-prices
 * list), never the model's free text — so a hand-built turn wrapping one real fixture
 * card is enough to exercise one assertion's own logic in isolation.
 *
 * Null-checks throw explicitly rather than relying on a PHPUnit assertion for
 * narrowing: this is a plain trait, not itself a TestCase subclass, so mago's analyzer
 * (correctly) does not resolve `self::assertNotNull()` here the way it would inside a
 * real test class.
 */
trait BuildsEvalCards
{
    use UsesCatalogFixture;

    private function turnWithCard(string $productId, string $prose = 'Here is what I found.'): AssistantTurn
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $card = $gateway->product($productId, new CatalogScope());

        if (null === $card) {
            throw new \RuntimeException(\sprintf('Fixture catalog has no product "%s".', $productId));
        }

        return new AssistantTurn(prose: $prose, cards: [$card], outcome: 'product_shown');
    }

    /**
     * A card that carries the exact id of a real variant but reports the parent
     * product's aggregate stock (15) under {@see StockSource::Parent} instead of the
     * variant's own stock (0) under {@see StockSource::Variant} — the failure mode
     * {@see \Swag\AssistantStarterKit\Eval\Assertion\StockMatchesSource} exists to catch.
     */
    private function turnWithParentStock(): AssistantTurn
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $variant = $gateway->product('fx-026-blue-m', new CatalogScope());

        if (null === $variant) {
            throw new \RuntimeException('Fixture catalog has no product "fx-026-blue-m".');
        }

        $corrupted = new ProductCard(
            id: $variant->id,
            parentId: $variant->parentId,
            name: $variant->name,
            description: $variant->description,
            price: $variant->price,
            currency: $variant->currency,
            stock: 15,
            stockSource: StockSource::Parent,
            deliveryTime: $variant->deliveryTime,
            url: $variant->url,
            imageUrl: $variant->imageUrl,
            options: $variant->options,
            categoryPath: $variant->categoryPath,
            properties: $variant->properties,
        );

        return new AssistantTurn(prose: 'It is in stock.', cards: [$corrupted], outcome: 'product_shown');
    }
}
