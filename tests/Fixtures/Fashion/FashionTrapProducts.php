<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * The four traps of the occasion-query spec, each with an id a failing assertion can name.
 *
 * No seeded state: these are literals, so a change to {@see FashionCatalogGenerator}'s arithmetic
 * cannot move them. That is the same division `ScaleTrapProducts` uses and for the same reason — a
 * trap whose position depends on the filler is a trap that silently stops being one.
 *
 * @phpstan-import-type FashionProduct from FashionCatalogGenerator
 * @phpstan-import-type FashionVariant from FashionCatalogGenerator
 */
final class FashionTrapProducts
{
    /** `fw-gender-split`, the women's side. Six occasion dresses, `Women > Occasion & Party > Occasion Dresses`. */
    public const OCCASION_DRESS_PREFIX = 'fw-occ-dress-';

    /** `fw-gender-split`, the men's side. Six occasion suits, `Men > Suits & Tailoring > Occasion Suits`. */
    public const OCCASION_SUIT_PREFIX = 'fw-occ-suit-';

    /**
     * `fw-false-friend`, and `fw-occasion-word` seen from the other side.
     *
     * The ONLY product in this catalogue whose name contains "wedding", and it is not a garment: a
     * cake-topper charm in `Gifts & Novelty > Keepsakes`. So a keyword search for the shopper's own
     * word returns exactly this and nothing else — which is worse than an empty result, because the
     * search technically succeeded and the model has something to answer with.
     */
    public const FALSE_FRIEND_ID = 'fw-false-friend';

    /**
     * `fw-undivided`, the negative control.
     *
     * Yoga wear exists under `Women > Activewear > Yoga` and nowhere else — no men's or kids'
     * equivalent. So the answer to "what should I wear to a yoga class" does not change with anything
     * the shopper could tell us, and the correct behaviour is to recommend without asking.
     *
     * Without this trap the suite can only prove the assistant asks, never that it asks selectively —
     * and "asks about everything" passes every other assertion in this work.
     */
    public const YOGA_PREFIX = 'fw-yoga-';

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const DRESSES = [
        ['cut' => 'Silk Slip Occasion Dress', 'colour' => 'Ivory', 'material' => 'Silk'],
        ['cut' => 'Pleated Midi Occasion Dress', 'colour' => 'Sage', 'material' => 'Viscose'],
        ['cut' => 'Draped Satin Occasion Gown', 'colour' => 'Navy', 'material' => 'Satin'],
        ['cut' => 'Embroidered Tulle Occasion Dress', 'colour' => 'Blush', 'material' => 'Cotton'],
        ['cut' => 'Cape-Back Occasion Dress', 'colour' => 'Emerald', 'material' => 'Silk'],
        ['cut' => 'Tiered Chiffon Occasion Dress', 'colour' => 'Lilac', 'material' => 'Viscose'],
    ];

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const SUITS = [
        ['cut' => 'Three-Piece Occasion Suit', 'colour' => 'Navy', 'material' => 'Wool'],
        ['cut' => 'Linen Occasion Suit', 'colour' => 'Stone', 'material' => 'Linen'],
        ['cut' => 'Double-Breasted Occasion Suit', 'colour' => 'Charcoal', 'material' => 'Wool'],
        ['cut' => 'Slim Morning Suit', 'colour' => 'Slate', 'material' => 'Wool'],
        ['cut' => 'Velvet Dinner Jacket Suit', 'colour' => 'Burgundy', 'material' => 'Velvet'],
        ['cut' => 'Herringbone Occasion Suit', 'colour' => 'Camel', 'material' => 'Wool'],
    ];

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const YOGA = [
        ['cut' => 'High-Waist Yoga Legging', 'colour' => 'Black', 'material' => 'Jersey'],
        ['cut' => 'Seamless Yoga Bra Top', 'colour' => 'Sage', 'material' => 'Jersey'],
        ['cut' => 'Wide-Leg Yoga Trouser', 'colour' => 'Slate', 'material' => 'Tencel'],
        ['cut' => 'Wrap Yoga Cardigan', 'colour' => 'Cream', 'material' => 'Cotton'],
    ];

    private const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    private function __construct() {}

    /** @return list<FashionProduct> */
    public static function all(): array
    {
        return [
            ...self::family(
                self::DRESSES,
                self::OCCASION_DRESS_PREFIX,
                ['Women', 'Occasion & Party', 'Occasion Dresses'],
                189.0,
            ),
            ...self::family(
                self::SUITS,
                self::OCCASION_SUIT_PREFIX,
                ['Men', 'Suits & Tailoring', 'Occasion Suits'],
                349.0,
            ),
            ...self::family(self::YOGA, self::YOGA_PREFIX, ['Women', 'Activewear', 'Yoga'], 59.0),
            self::falseFriend(),
        ];
    }

    /**
     * @param list<array{cut: string, colour: string, material: string}> $items
     * @param list<string>                                              $path
     *
     * @return list<FashionProduct>
     */
    private static function family(array $items, string $prefix, array $path, float $basePrice): array
    {
        $products = [];

        foreach ($items as $offset => $item) {
            $id = $prefix . ($offset + 1);
            $price = round($basePrice + ($offset * 20.0), 2);

            $products[] = [
                'id' => $id,
                'name' => $item['cut'],
                'description' => \sprintf(
                    '%s in %s %s.',
                    $item['cut'],
                    strtolower($item['colour']),
                    strtolower($item['material']),
                ),
                'price' => $price,
                'stock' => 4 + $offset,
                'url' => '/detail/' . $id,
                'categoryPath' => $path,
                'properties' => ['Colour' => [$item['colour']], 'Material' => [$item['material']]],
                'variants' => self::sizes($id, $price),
            ];
        }

        return $products;
    }

    /**
     * @return list<FashionVariant>
     */
    private static function sizes(string $parentId, float $price): array
    {
        $variants = [];

        foreach (self::SIZES as $offset => $size) {
            $variants[] = [
                'id' => $parentId . '-' . strtolower($size),
                'options' => ['Size' => $size],
                'price' => $price,
                'stock' => ($offset + 1) % 4,
            ];
        }

        return $variants;
    }

    /**
     * The one product in the catalogue that carries the shopper's own word, and cannot be worn.
     *
     * Variant-free and cheap, so nothing about it invites a recommendation on its own merits: if it
     * reaches a shopper as an answer to what to wear, the only reason is the keyword.
     *
     * @return FashionProduct
     */
    private static function falseFriend(): array
    {
        return [
            'id' => self::FALSE_FRIEND_ID,
            'name' => 'Wedding Cake Topper Charm',
            'description' => 'Small enamel keepsake charm, boxed.',
            'price' => 12.0,
            'stock' => 30,
            'url' => '/detail/' . self::FALSE_FRIEND_ID,
            'categoryPath' => ['Gifts & Novelty', 'Keepsakes'],
            'properties' => ['Material' => ['Silver']],
            'variants' => [],
        ];
    }
}
