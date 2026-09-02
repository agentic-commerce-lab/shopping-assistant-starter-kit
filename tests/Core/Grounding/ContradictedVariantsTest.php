<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ContradictedVariants;

/**
 * A reply that names other values of a variant's own option group has ruled that variant out.
 *
 * Measured on staging, 2026-09-02: *"Ich habe das Club Jersey sowohl in Größe M als auch in Größe L
 * gefunden"* rendered a card reading **Size: XL**. Every variant shares the family's name, so the name
 * resolved to whichever variant came first — a size the sentence had excluded.
 */
final class ContradictedVariantsTest extends TestCase
{
    public function testAVariantWhoseSizeTheReplyRulesOutIsExcluded(): void
    {
        $excluded = ContradictedVariants::in('Ich habe das Club Jersey sowohl in Größe M als auch in Größe L gefunden.', [
            self::card('id-m', 'M'),
            self::card('id-l', 'L'),
            self::card('id-xl', 'XL'),
        ]);

        self::assertSame(['id-xl'], $excluded);
    }

    /** A reply that names no size at all rules nothing out. */
    public function testAReplyMentioningNoValueOfTheGroupExcludesNothing(): void
    {
        $excluded = ContradictedVariants::in('Das Club Jersey ist aus atmungsaktivem Polyester.', [
            self::card('id-m', 'M'),
            self::card('id-xl', 'XL'),
        ]);

        self::assertSame([], $excluded);
    }

    /**
     * Word boundaries, because sizes are single letters. Without them `M` matches inside "Merino",
     * "Material" and most German sentences, and every size would look mentioned in every reply.
     */
    public function testASizeLetterInsideAWordIsNotAMention(): void
    {
        $excluded = ContradictedVariants::in('Das Trikot ist aus Merino und Mesh gefertigt.', [
            self::card('id-m', 'M'),
            self::card('id-xl', 'XL'),
        ]);

        self::assertSame([], $excluded);
    }

    /** A value no retrieved card carries cannot rule a retrieved card out. */
    public function testAValueOutsideTheRetrievedVocabularyIsIgnored(): void
    {
        $excluded = ContradictedVariants::in('In Größe XXL habe ich nichts gefunden.', [
            self::card('id-m', 'M'),
            self::card('id-l', 'L'),
        ]);

        self::assertSame([], $excluded);
    }

    /** A product with no options at all is never contradicted. */
    public function testAProductWithoutOptionsIsNeverExcluded(): void
    {
        $card = new ProductCard(
            id: 'id-plain',
            parentId: null,
            name: 'Bike Wash 1L',
            description: null,
            price: 16.9,
            currency: 'EUR',
            stock: 17,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/bike-wash',
            imageUrl: null,
        );

        self::assertSame([], ContradictedVariants::in('Das Bike Wash 1L in Größe M.', [$card]));
    }

    private static function card(string $id, string $size): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: 'family',
            name: 'Club Jersey',
            description: null,
            price: 59.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/' . $id,
            imageUrl: null,
            options: ['Colour' => 'Red', 'Size' => $size],
        );
    }
}
