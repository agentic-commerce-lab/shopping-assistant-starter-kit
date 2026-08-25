<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * Builds a catalogue two orders of magnitude larger than the one every eval runs against, so the
 * constants this kit sets against seventeen sellable units can be observed above them.
 *
 * **Seeded arithmetic, not `random_int()`.** A fixture that differs between runs turns a red eval
 * into a coin toss, and spec decision S2 chose a generator over a committed blob only because a
 * generator can be deterministic *and* reviewable. The sequence below is a linear congruential
 * generator with the constants glibc uses — not because its statistical quality matters here (it
 * does not; nothing is being sampled) but because it is four lines, needs no extension, and gives
 * the same numbers on every platform and PHP version. `mt_srand()` would do the job on one machine
 * and quietly not on another.
 *
 * **The twelve real products are copied verbatim** (S3). Every existing journey asserts against
 * their ids, prices and stock; perturbing one would make a red journey at scale unreadable. They are
 * emitted first, so their insertion order is unchanged too.
 *
 * This class owns the *volume* and the broad-term trap that volume carries. The three placed traps
 * live in {@see ScaleTrapProducts}, which shares none of the seeded state. *
 * The shape below is declared once, here, because this class is what defines it — every other
 * large-catalogue class imports it rather than restating it. Restating it was the alternative, and a
 * restated shape drifts: the analyzer would keep passing while one copy quietly said
 * `array<string, mixed>` and turned every field access downstream into `mixed`.
 *
 * @phpstan-type LargeVariant array{id: string, options: array<string, string>, price: float, stock: int}
 * @phpstan-type LargeProduct array{
 *     id: string,
 *     name: string,
 *     description: string|null,
 *     price: float,
 *     stock: int,
 *     url: string,
 *     categoryPath: list<string>,
 *     properties: array<string, list<string>>,
 *     variants: list<LargeVariant>,
 * }
 * @phpstan-type LargeCatalogue array{products: list<LargeProduct>}
 */
final class LargeCatalogGenerator
{
    /**
     * How many products carry the broad term, and the bulk of the volume.
     *
     * The arithmetic, because the unit count is asserted and a guess would fail the test: 500
     * products, of which every fifth has no variants (100 products, 100 units) and the rest have
     * five (400 × 5 = 2,000 units). With the thirty-variant family, the two single-unit traps and
     * the twelve real products' seventeen units, that is **2,149 sellable units** — past
     * `MIN_CANDIDATES` (20) and `MAX_CANDIDATES` (50) by enough that no window can hold a meaningful
     * fraction of a broad result. Measured after implementation: exactly 2,149.
     *
     * Keeping a fifth of them variant-free is deliberate: `StockSource::Product` and
     * `StockSource::Variant` both need to occur at scale, and a catalogue of nothing but families
     * would not exercise the distinction that cost every simple product its add-to-cart button once.
     */
    public const GENERATED_PRODUCTS = 500;

    /** Sizes for the four fifths of filler products that get a family. */
    private const FILLER_SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    /** Nouns for generated names, so they vary without needing a word list. */
    private const NOUNS = ['Jersey', 'Bottle', 'Pump', 'Saddle', 'Grip', 'Light', 'Tyre', 'Cap'];

    /** Past `CatalogVocabularyBudget::MAX_FIELDS` (30). */
    private const GENERATED_GROUPS = 56;

    /** Past `Dal\DalCommerceGateway::FACET_VALUE_LIMIT` (50) inside one group. */
    private const COLOUR_VALUES = 60;

    /**
     * How many distinct values one generated group may carry. Nine, so 56 groups × ~9 values clears
     * the several hundred distinct pairs spec decision S4 asks for; see {@see self::valueIndex()}
     * for why the obvious modulo gave one value per group instead.
     */
    private const VALUES_PER_GROUP = 9;

    private int $state;

    public function __construct(
        private readonly string $smallCatalogPath,
        int $seed = 20_260_825,
    ) {
        $this->state = $seed;
    }

    /** @return LargeCatalogue */
    public function build(): array
    {
        $products = $this->smallCatalogue();

        for ($i = 1; $i <= self::GENERATED_PRODUCTS; ++$i) {
            $products[] = $this->filler($i);
        }

        // Placed last, which is the `sc-deep-duplicate` trap's whole point: it has to sit deep enough
        // in insertion order that reaching it needs a wide candidate window.
        $products[] = ScaleTrapProducts::thirtyVariantFamily();
        $products[] = ScaleTrapProducts::rareOption();
        $products[] = ScaleTrapProducts::deepDuplicate();

        return ['products' => $products];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->build(),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return list<LargeProduct> */
    private function smallCatalogue(): array
    {
        $json = file_get_contents($this->smallCatalogPath);

        if (false === $json) {
            throw new \RuntimeException(\sprintf('Unable to read "%s".', $this->smallCatalogPath));
        }

        /** @var LargeCatalogue $decoded */
        $decoded = json_decode($json, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        return $decoded['products'];
    }

    /**
     * One ordinary product, and the volume the traps hide in.
     *
     * Every name carries {@see ScaleTrap::BROAD_TERM_WORD} — a coined word, so the broad-term trap
     * cannot be satisfied by a real word that happens to be common. Every fifth product is
     * variant-free and the rest carry five sizes; see {@see self::GENERATED_PRODUCTS} for why, and
     * for the arithmetic the unit-count assertion depends on.
     *
     * @return LargeProduct
     */
    private function filler(int $index): array
    {
        $group = 'Attribute ' . ($index % self::GENERATED_GROUPS);
        $id = \sprintf('gen-%04d', $index);
        $name = \sprintf('%s %s %04d', ScaleTrap::BROAD_TERM_WORD, $this->noun($index), $index);
        $price = 5.0 + ((float) ($this->next() % 20_000) / 100.0);
        $variants = [];

        if (0 !== ($index % 5)) {
            foreach (self::FILLER_SIZES as $size) {
                $variants[] = [
                    'id' => \sprintf('%s-%s', $id, strtolower($size)),
                    'options' => ['Size' => $size],
                    'price' => $price,
                    'stock' => 1 + (int) ($this->next() % 40),
                ];
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'description' => \sprintf('Generated filler product %d.', $index),
            'price' => $price,
            'stock' => 1 + (int) ($this->next() % 40),
            'url' => '/detail/' . $id,
            'categoryPath' => ['Generated', $this->noun($index)],
            'properties' => [
                $group => ['Value ' . $this->valueIndex($index)],
                'Colour' => ['Shade ' . ($index % self::COLOUR_VALUES)],
            ],
            'variants' => $variants,
        ];
    }

    /**
     * Which value of its group a filler product carries.
     *
     * `intdiv`, not `%`, and that is the whole point. The obvious `$index % 7` gives every group
     * exactly **one** value: the group is `$index % 56`, 56 is a multiple of 7, so `$index % 56`
     * already determines `$index % 7` and the two moduli move together. Measured before this was
     * fixed, that produced 116 distinct group/value pairs where spec decision S4 needs several
     * hundred — a fixture that crosses `MAX_FIELDS` while quietly staying under `FACET_VALUE_LIMIT`
     * and `MAX_VALUES_PER_FIELD`, which is half the thing being measured.
     *
     * Dividing instead walks the value on each full pass through the groups, so the ~9 products in a
     * group get ~9 distinct values: 600 pairs, measured. {@see self::VALUES_PER_GROUP} bounds it —
     * with 500 products over 56 groups the quotient never reaches 9, so the modulo guards a future
     * larger `GENERATED_PRODUCTS` rather than wrapping today.
     */
    private function valueIndex(int $index): int
    {
        return intdiv($index, self::GENERATED_GROUPS) % self::VALUES_PER_GROUP;
    }

    /**
     * One of eight nouns, so generated names vary without needing a word list.
     *
     * The `??` is not defensive padding: `$index % count` is provably in range to a reader but not
     * to the analyzer, which infers `string|null` from any variable array index and fails the
     * typecheck gate. Coalescing to a constant element is the narrowest way to say so — the branch
     * is unreachable, and if it ever were reached the result would still be a valid noun rather than
     * a null that surfaces three layers away as an unnamed product.
     */
    private function noun(int $index): string
    {
        return self::NOUNS[$index % \count(self::NOUNS)] ?? self::NOUNS[0];
    }

    /**
     * The next value in the seeded sequence.
     *
     * A linear congruential generator with glibc's constants. Deterministic across platforms and PHP
     * versions, which `mt_rand()` is not guaranteed to be — and cross-platform determinism is the
     * whole reason this class exists rather than a committed JSON blob.
     */
    private function next(): int
    {
        $this->state = (($this->state * 1_103_515_245) + 12_345) & 0x7FFF_FFFF;

        return $this->state;
    }
}
