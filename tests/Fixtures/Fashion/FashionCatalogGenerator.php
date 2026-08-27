<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * A fashion catalogue at the scale of a real shop: ~15,200 sellable units across ~1,043 category
 * nodes.
 *
 * **Why a second generator rather than a parameter on the first.** `LargeCatalogGenerator` crosses the
 * product-side constants — candidate windows, variant families, facet value limits. Nothing in this
 * kit has ever met a category TREE, and the tree is what this one is for: the word a shopper says
 * ("wedding") is not in the catalogue at all, and the only route from that word to a product runs
 * through category names the shopper never typed.
 *
 * **Seeded arithmetic, not `random_int()`** — same reasoning as `LargeCatalogGenerator`, whose LCG
 * constants and comment this borrows: a fixture that differs between runs turns a red eval into a coin
 * toss. The seeded sequence decides properties, stock and price only; which category a product lands
 * in is index arithmetic, so every leaf is non-empty and the node count is a constant rather than a
 * function of the seed. See {@see FashionTaxonomy::garmentLeaf()}.
 *
 * **The twelve real products are copied verbatim** (spec O10), first, in order, and INCLUDING their
 * category paths — so all fifteen existing journeys run against this catalogue unchanged. Their
 * departments (`Apparel`, `Accessories`, `Maintenance`, `Brakes`, `Merch`, `Restricted`, `Tyres`)
 * therefore appear beside `Women`/`Men`/`Kids` at the top level, which is a slightly odd shop and the
 * correct trade.
 *
 * An earlier draft re-pathed them under a `Sport` department. That would have broken
 * `page_context_not_a_cage`, which stands the shopper in the category `Jerseys` and expects the
 * Commuter Glove out of `Apparel > Gloves`: re-pathing deletes both names, and the P9 journey with
 * them. `FixtureCategoryFilter::apply()` matches on the names in `categoryPath`, so a path here is not
 * decoration.
 *
 * **The word "wedding" appears in exactly one product, and it is not wearable** —
 * {@see FashionTrapProducts::FALSE_FRIEND_ID}. `FashionTrapPresenceTest` asserts that over the whole
 * encoded output, because it is the premise the entire measurement rests on and one stray generated
 * name would quietly void it.
 *
 * @phpstan-type FashionVariant array{id: string, options: array<string, string>, price: float, stock: int}
 * @phpstan-type FashionProduct array{
 *     id: string,
 *     name: string,
 *     description: string|null,
 *     price: float,
 *     stock: int,
 *     url: string,
 *     categoryPath: list<string>,
 *     properties: array<string, list<string>>,
 *     variants: list<FashionVariant>,
 * }
 * @phpstan-type FashionCatalogue array{products: list<FashionProduct>}
 */
final class FashionCatalogGenerator
{
    /**
     * Every distinct category node the catalogue produces.
     *
     * 969 from the garment tree (3 departments + 3 × 14 type nodes + 3 × 14 × 22 leaves), 57 from the
     * `Brand` / `Season` / `Occasion` branches (3 parents + 40 + 4 + 10 leaves), 5 from the traps, and
     * 12 brought in verbatim by the twelve real products. **Measured, 2026-08-26: 1,043.**
     *
     * Asserted rather than described. A tree the generator quietly halves is a measurement about a
     * catalogue nobody has.
     */
    public const CATEGORY_NODES = 1_043;

    /**
     * How many generated parent products, and the arithmetic the unit count depends on.
     *
     * 3,600 parents, of which every fifth is variant-free (720 parents, 720 units) and the rest carry
     * five sizes (2,880 × 5 = 14,400 units) = 15,120.
     *
     * Keeping a fifth variant-free is deliberate, and the same reason `LargeCatalogGenerator` gives:
     * `StockSource::Product` and `StockSource::Variant` must both occur at scale, and a catalogue of
     * nothing but families would not exercise the distinction that once cost every simple product its
     * add-to-cart button.
     */
    public const GENERATED_PARENTS = 3_600;

    /**
     * 15,120 generated + 17 from the twelve real products + 81 from the traps.
     *
     * **Measured, 2026-08-26: 15,218.** The arithmetic is deterministic, so the measured number IS the
     * correct constant — which is why the test asserts equality rather than a floor.
     */
    public const SELLABLE_UNITS = 15_218;

    /** Sizes for the four fifths of generated products that get a family. */
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    private const COLOURS = [
        'Black',
        'Ivory',
        'Navy',
        'Camel',
        'Sage',
        'Rust',
        'Blush',
        'Slate',
        'Olive',
        'Burgundy',
        'Cobalt',
        'Mustard',
        'Lilac',
        'Teal',
        'Charcoal',
        'Cream',
        'Emerald',
        'Coral',
        'Denim Blue',
        'Chocolate',
        'Silver',
        'Gold',
        'Plum',
        'Stone',
    ];

    private const MATERIALS = [
        'Cotton',
        'Linen',
        'Silk',
        'Wool',
        'Cashmere',
        'Viscose',
        'Tencel',
        'Denim',
        'Leather',
        'Satin',
        'Velvet',
        'Jersey',
    ];

    private const PATTERNS = [
        'Plain',
        'Striped',
        'Floral',
        'Checked',
        'Polka Dot',
        'Houndstooth',
        'Paisley',
        'Geometric',
    ];

    private int $state;

    public function __construct(
        private readonly string $smallCatalogPath,
        int $seed = 20_260_826,
    ) {
        $this->state = $seed;
    }

    /** @return FashionCatalogue */
    public function build(): array
    {
        $products = $this->smallCatalogue();

        foreach (FashionTrapProducts::all() as $trap) {
            $products[] = $trap;
        }

        for ($index = 0; $index < self::GENERATED_PARENTS; ++$index) {
            $products[] = $this->filler($index);
        }

        return ['products' => $products];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->build(),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return list<FashionProduct> */
    private function smallCatalogue(): array
    {
        $json = file_get_contents($this->smallCatalogPath);

        if (false === $json) {
            throw new \RuntimeException(\sprintf('Unable to read "%s".', $this->smallCatalogPath));
        }

        /** @var FashionCatalogue $decoded */
        $decoded = json_decode($json, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        return $decoded['products'];
    }

    /**
     * One ordinary product, and the volume the traps hide in.
     *
     * The first {@see FashionTaxonomy::sideLeafCount()} products fill the `Brand` / `Season` /
     * `Occasion` branches one apiece; the rest cycle through the garment leaves so none is empty.
     *
     * @return FashionProduct
     */
    private function filler(int $index): array
    {
        $sideLeaves = FashionTaxonomy::sideLeafCount();
        $leaf = $index < $sideLeaves
            ? FashionTaxonomy::sideLeaf($index)
            : FashionTaxonomy::garmentLeaf($index - $sideLeaves);

        $id = \sprintf('fwg-%04d', $index);
        $price = 19.0 + ((float) ($this->next() % 28_000) / 100.0);

        return [
            'id' => $id,
            'name' => \sprintf('%s %04d', $leaf['name'], $index),
            'description' => \sprintf('%s in a considered cut.', $leaf['name']),
            'price' => round($price, 2),
            'stock' => $this->next() % 12,
            'url' => '/detail/' . $id,
            'categoryPath' => $leaf['path'],
            'properties' => [
                'Colour' => [self::COLOURS[$this->next() % \count(self::COLOURS)] ?? 'Black'],
                'Material' => [self::MATERIALS[$this->next() % \count(self::MATERIALS)] ?? 'Cotton'],
                'Pattern' => [self::PATTERNS[$this->next() % \count(self::PATTERNS)] ?? 'Plain'],
            ],
            'variants' => ($index % 5) === 0 ? [] : $this->sizeFamily($id, round($price, 2)),
        ];
    }

    /**
     * Five sizes, priced as the parent and stocked independently.
     *
     * @return list<FashionVariant>
     */
    private function sizeFamily(string $parentId, float $price): array
    {
        $variants = [];

        foreach (self::SIZES as $size) {
            $variants[] = [
                'id' => $parentId . '-' . strtolower($size),
                'options' => ['Size' => $size],
                'price' => $price,
                'stock' => $this->next() % 9,
            ];
        }

        return $variants;
    }

    /**
     * The next value in the seeded sequence.
     *
     * A linear congruential generator with glibc's constants, lifted from `LargeCatalogGenerator`
     * along with its reasoning: deterministic across platforms and PHP versions, which `mt_rand()` is
     * not guaranteed to be — and cross-platform determinism is the whole reason this class exists
     * rather than a committed JSON blob.
     */
    private function next(): int
    {
        $this->state = (($this->state * 1_103_515_245) + 12_345) & 0x7FFF_FFFF;

        return $this->state;
    }
}
