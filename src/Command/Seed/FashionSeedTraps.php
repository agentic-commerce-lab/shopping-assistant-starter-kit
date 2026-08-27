<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The four named traps of the occasion-query spec, duplicated from
 * {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTrapProducts} for the same reason
 * {@see FashionSeedTaxonomy} duplicates the category shape — `tests/` is not autoloaded inside a
 * running Shopware installation. {@see FashionSeedTrapsParityTest} keeps the two in sync.
 *
 * Unlike the fixture class this returns plain description arrays rather than a full product shape —
 * {@see ProductPlan} owns turning these into DAL write payloads (ids, prices, variants), the same
 * division {@see FashionTaxonomy} and {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogGenerator}
 * already use between "what the shop sells" and "how much of it there is".
 */
final class FashionSeedTraps
{
    public const OCCASION_DRESS_PREFIX = 'fw-occ-dress-';
    public const OCCASION_SUIT_PREFIX = 'fw-occ-suit-';
    public const FALSE_FRIEND_ID = 'fw-false-friend';
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

    private function __construct() {}

    /**
     * @return list<array{
     *     id: string, name: string, description: string, price: float,
     *     categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool,
     * }>
     */
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
     * @return list<array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool}>
     */
    private static function family(array $items, string $prefix, array $path, float $basePrice): array
    {
        $products = [];

        foreach ($items as $offset => $item) {
            $products[] = [
                'id' => $prefix . ($offset + 1),
                'name' => $item['cut'],
                'description' => \sprintf(
                    '%s in %s %s.',
                    $item['cut'],
                    strtolower($item['colour']),
                    strtolower($item['material']),
                ),
                'price' => round($basePrice + ($offset * 20.0), 2),
                'categoryPath' => $path,
                'properties' => ['Colour' => [$item['colour']], 'Material' => [$item['material']]],
                'sizes' => true,
            ];
        }

        return $products;
    }

    /**
     * @return array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool}
     */
    private static function falseFriend(): array
    {
        return [
            'id' => self::FALSE_FRIEND_ID,
            'name' => 'Wedding Cake Topper Charm',
            'description' => 'Small enamel keepsake charm, boxed.',
            'price' => 12.0,
            'categoryPath' => ['Gifts & Novelty', 'Keepsakes'],
            'properties' => ['Material' => ['Silver']],
            'sizes' => false,
        ];
    }
}
