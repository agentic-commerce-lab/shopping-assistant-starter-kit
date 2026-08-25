<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * The generator's contract: the superset property, the scale counts, determinism, and that the
 * gateway can actually read what comes out.
 *
 * The generator is test infrastructure, and test infrastructure that is wrong produces a green run
 * that means nothing. These assertions are the reason a generated fixture is trustworthy at all —
 * see spec decision S2, which chose a generator over a committed blob precisely so this file could
 * exist.
 *
 * The four traps' own shapes are asserted next door in {@see ScaleTrapPresenceTest}.
 *
 * @phpstan-import-type LargeCatalogue from LargeCatalogGenerator
 */
final class LargeCatalogGeneratorTest extends TestCase
{
    /**
     * Spec decision S3, and the single most important assertion in this file. Every existing journey
     * asserts against these twelve products by id, price and stock; if the generator perturbs one,
     * a red journey at scale means nothing.
     */
    public function testAllTwelveOriginalProductsSurviveVerbatim(): void
    {
        // The committed fixture is trusted input, so its shape is declared rather than validated:
        // if it ever stopped matching, every other test in this directory would fail first and more
        // loudly than a check here would.
        /** @var LargeCatalogue $small */
        $small = json_decode(
            (string) file_get_contents(LargeCatalogQuery::smallCatalogPath()),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );
        $large = LargeCatalogQuery::built();

        self::assertCount(12, $small['products']);

        foreach ($small['products'] as $original) {
            self::assertSame(
                $original,
                LargeCatalogQuery::find($large, $original['id']),
                \sprintf('product %s changed', $original['id']),
            );
        }
    }

    /**
     * Spec decision S4: the point is to cross every constant, so the counts are the contract.
     *
     * Floors rather than equalities, so adding a trap later does not fail this test for the right
     * reason — but a *drop* below one means the filler stopped generating, which is how this fixture
     * would quietly stop testing anything.
     */
    public function testItCrossesEveryConstantThisKitSets(): void
    {
        $large = LargeCatalogQuery::built();

        // 2,149 by the arithmetic in LargeCatalogGenerator::GENERATED_PRODUCTS, measured.
        self::assertGreaterThan(1_900, LargeCatalogQuery::sellableUnits($large));

        $vocabulary = LargeCatalogQuery::vocabulary($large);

        // Past MAX_FIELDS (30). Measured: 60.
        self::assertGreaterThan(55, \count($vocabulary['groups']));
        // Past FACET_VALUE_LIMIT (50) and MAX_VALUES_PER_FIELD (25). Measured: 600.
        self::assertGreaterThan(350, \count($vocabulary['pairs']));
    }

    /** Spec decision S2: determinism is what a generated fixture has instead of being committed. */
    public function testTheSameSeedProducesByteIdenticalOutput(): void
    {
        self::assertSame(
            LargeCatalogQuery::generator(seed: 4242)->toJson(),
            LargeCatalogQuery::generator(seed: 4242)->toJson(),
        );
    }

    public function testADifferentSeedProducesDifferentOutput(): void
    {
        self::assertNotSame(
            LargeCatalogQuery::generator(seed: 1)->toJson(),
            LargeCatalogQuery::generator(seed: 2)->toJson(),
        );
    }

    /** The gateway is the real consumer; if it cannot read the output, nothing else matters. */
    public function testTheFixtureGatewayCanReadTheResult(): void
    {
        $path = \sprintf('%s/swag-assistant-scale-test-%d.json', sys_get_temp_dir(), getmypid());
        file_put_contents($path, LargeCatalogQuery::generator()->toJson());

        try {
            $card = FixtureCommerceGateway::fromFile($path)->product(
                ScaleTrap::FAMILY_SOLD_OUT_VARIANT,
                new CatalogScope(),
            );

            self::assertNotNull($card);
            self::assertSame(0, $card->stock);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
