<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies;

/**
 * What the reply says about the variants it did NOT return.
 *
 * Measured before this existed: asked for `Size 30` of a thirty-variant family, the assistant
 * rendered variants 1 through 5 — 0/3 runs on both archetypes. Retrieval finds the right unit the
 * moment the option is supplied; the model never supplied it, because nothing had told it `Size 30`
 * exists. This class is what tells it.
 *
 * @see docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md
 */
final class TruncatedFamiliesTest extends TestCase
{
    /** @param array<string, string> $options */
    private static function variant(string $id, string $parentId, string $name, array $options): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: $name,
            description: null,
            price: 49.9,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }

    /** @return list<ProductCard> */
    private static function tyreFamily(): array
    {
        return [
            self::variant('fx-030-black-700', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Black', 'Size' => '700x40']),
            self::variant('fx-030-black-650', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Black', 'Size' => '650x47']),
            self::variant('fx-030-tan-700', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Tan', 'Size' => '700x40']),
            self::variant('fx-030-tan-650', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Tan', 'Size' => '650x47']),
        ];
    }

    public function testATruncatedFamilyReportsEveryOptionValueIncludingTheWithheldOnes(): void
    {
        $survivors = self::tyreFamily();

        $families = TruncatedFamilies::of($survivors, \array_slice($survivors, offset: 0, length: 2));

        self::assertCount(1, $families);
        self::assertSame('Gravel Tyre 40c', $families[0]['name']);
        self::assertSame(2, $families[0]['shown']);
        self::assertSame(4, $families[0]['variants']);

        // The point of the whole change: `Tan` and `650x47` are only on withheld variants, and the
        // model must still learn they exist.
        self::assertSame(['Black', 'Tan'], $families[0]['options']['Colour'] ?? []);
        self::assertSame(['700x40', '650x47'], $families[0]['options']['Size'] ?? []);
    }

    /** Nothing withheld, nothing to say. A summary here would be noise the model has to read. */
    public function testAFamilyReturnedWholeProducesNoSummary(): void
    {
        $survivors = self::tyreFamily();

        self::assertSame([], TruncatedFamilies::of($survivors, $survivors));
    }

    public function testTwoFamiliesAreSummarisedSeparately(): void
    {
        $tyres = self::tyreFamily();
        $jerseyM = self::variant('fx-026-blue-m', 'fx-026', 'Trail Jersey', ['Colour' => 'Blue', 'Size' => 'M']);
        $jerseyL = self::variant('fx-026-blue-l', 'fx-026', 'Trail Jersey', ['Colour' => 'Blue', 'Size' => 'L']);
        $oneTyre = self::variant('fx-030-black-700', 'fx-030', 'Gravel Tyre 40c', [
            'Colour' => 'Black',
            'Size' => '700x40',
        ]);

        $families = TruncatedFamilies::of([...$tyres, $jerseyM, $jerseyL], [$oneTyre, $jerseyM]);

        self::assertSame(['Gravel Tyre 40c', 'Trail Jersey'], array_column($families, 'name'));
        self::assertSame([1, 1], array_column($families, 'shown'));
        self::assertSame([4, 2], array_column($families, 'variants'));
    }

    /**
     * A product with no parent is not a family, and cannot be truncated into one.
     *
     * The tyre family in the same call IS truncated, so the assertion is "exactly one summary, and it
     * is not the standalone" — not "no summaries". An earlier version of this test asserted the
     * latter and failed for the right reason: the family beside it genuinely had something to say.
     */
    public function testStandaloneProductsAreNotFamilies(): void
    {
        $standalone = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Unnamed Chain Lube',
            description: null,
            price: 8.5,
            currency: 'EUR',
            stock: 20,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/fx-001',
            imageUrl: null,
        );

        $families = TruncatedFamilies::of([$standalone, ...self::tyreFamily()], [$standalone]);

        self::assertSame(['Gravel Tyre 40c'], array_column($families, 'name'));
        self::assertNotContains('Unnamed Chain Lube', array_column($families, 'name'));
    }

    public function testTheOptionValueListIsCappedAndSaysSo(): void
    {
        $survivors = [];
        for ($i = 1; $i <= (TruncatedFamilies::MAX_OPTION_VALUES + 10); ++$i) {
            $survivors[] = self::variant(\sprintf('fx-big-%03d', $i), 'fx-big', 'Endurance Bib Tights', [
                'Size' => 'Size ' . $i,
            ]);
        }

        $families = TruncatedFamilies::of($survivors, [$survivors[0]]);

        self::assertCount(TruncatedFamilies::MAX_OPTION_VALUES, $families[0]['options']['Size'] ?? []);
        self::assertTrue($families[0]['options_truncated']);
        // The real count is still reported, so the model is not told the family is 50 wide.
        self::assertSame(TruncatedFamilies::MAX_OPTION_VALUES + 10, $families[0]['variants']);
    }

    /**
     * Spec decision T6, and the assertion this file exists to carry as much as the grouping.
     *
     * `ToolProductSummary`'s docblock says "Never widen this" about figures reaching the model, and
     * this class handles cards that carry a price and a stock figure. A summary that leaked either
     * would let the model quote a number it did not earn — the one failure the whole pipeline is
     * built to prevent.
     */
    public function testNoFigureEverReachesTheSummary(): void
    {
        $shown = self::variant('fx-030-black-700', 'fx-030', 'Gravel Tyre 40c', [
            'Colour' => 'Black',
            'Size' => '700x40',
        ]);

        $encoded = json_encode(TruncatedFamilies::of(self::tyreFamily(), [$shown]), \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'deliveryTime', 'url', '49.9', 'EUR'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, \sprintf('%s leaked', $forbidden));
        }
    }
}
