<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTrapProducts;

/**
 * Same reasoning as {@see FashionSeedTaxonomyParityTest}: the seed command cannot see `tests/`, so the
 * four named traps (`fw-gender-split`'s two halves, `fw-false-friend`, `fw-undivided`) are duplicated
 * into `src/`. Without this test a stray edit to either copy silently stops the seeded shop and the
 * fixture eval from being the same measurement.
 */
final class FashionSeedTrapsParityTest extends TestCase
{
    public function testPrefixesAndIdsMatch(): void
    {
        self::assertSame(FashionTrapProducts::OCCASION_DRESS_PREFIX, FashionSeedTraps::OCCASION_DRESS_PREFIX);
        self::assertSame(FashionTrapProducts::OCCASION_SUIT_PREFIX, FashionSeedTraps::OCCASION_SUIT_PREFIX);
        self::assertSame(FashionTrapProducts::YOGA_PREFIX, FashionSeedTraps::YOGA_PREFIX);
        self::assertSame(FashionTrapProducts::FALSE_FRIEND_ID, FashionSeedTraps::FALSE_FRIEND_ID);
    }

    public function testEveryTrapNameAndCategoryPathMatchesTheFixture(): void
    {
        $fixture = FashionTrapProducts::all();
        $seed = FashionSeedTraps::all();

        self::assertCount(\count($fixture), $seed);

        foreach ($fixture as $index => $fixtureProduct) {
            self::assertSame($fixtureProduct['id'], $seed[$index]['id']);
            self::assertSame($fixtureProduct['name'], $seed[$index]['name']);
            self::assertSame($fixtureProduct['categoryPath'], $seed[$index]['categoryPath']);
            self::assertSame($fixtureProduct['properties'], $seed[$index]['properties']);
        }
    }

    /**
     * The premise the whole trap set rests on (`fw-occasion-word`): "wedding" appears in exactly the
     * false friend and nowhere else. Asserted over the encoded output, mirroring
     * `FashionTrapPresenceTest` on the fixture side rather than trusting a comment.
     */
    public function testWeddingAppearsOnlyInTheFalseFriend(): void
    {
        foreach (FashionSeedTraps::all() as $product) {
            $haystack = strtolower($product['name'] . ' ' . $product['description']);
            $mentionsWedding = str_contains($haystack, 'wedding');

            self::assertSame($product['id'] === FashionSeedTraps::FALSE_FRIEND_ID, $mentionsWedding, $product['id']);
        }
    }
}
