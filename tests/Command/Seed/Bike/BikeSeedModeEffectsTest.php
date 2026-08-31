<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedMode;

/**
 * What each mode actually does once it is allowed to run: writes, indexes, marks.
 *
 * Split from {@see BikeSeedModeTest} at mago's eleven-method ceiling, and the ceiling picked the right
 * line. That file answers *may this run proceed against a shop in this state* — the marker in both
 * directions, and the message when the answer is no. This one answers *and then what does it touch*,
 * which is where the two conflations that actually bit live: the marker with the reindex, and the
 * product data with the structures whose ids the DAL mints.
 */
final class BikeSeedModeEffectsTest extends TestCase
{
    public function testOnlyADryRunSkipsTheWrite(): void
    {
        self::assertTrue(BikeSeedMode::Seed->writes());
        self::assertTrue(BikeSeedMode::Update->writes());
    }

    /**
     * An update must not run completion. Marking twice would be harmless on its own — the marker id
     * is derived too — but completion is also what re-indexes and dispatches, and a first seed's
     * "this finished" signal must not be forgeable by a later partial update.
     */
    public function testOnlyAFirstSeedMarksTheShopAsSeeded(): void
    {
        self::assertTrue(BikeSeedMode::Seed->marks());
        self::assertFalse(BikeSeedMode::Update->marks());
        self::assertFalse(BikeSeedMode::DryRun->marks());
    }

    /**
     * An update must reindex, even though it must not mark.
     *
     * **Conflated once, and it cost a manual step.** The first working `--update` against staging left
     * the product index stale because `marks()` gated `SeedCompletion::complete()`, which does the
     * indexing *and* the marker in one call — so skipping the marker skipped the reindex with it, and
     * `dal:refresh:index` had to be run by hand. Shopware resolves a variant's inherited properties
     * through that index, so a catalogue whose properties changed and whose index did not is a
     * catalogue the storefront filters cannot see.
     */
    public function testAnUpdateReindexesEvenThoughItDoesNotMark(): void
    {
        self::assertTrue(BikeSeedMode::Update->indexes());
        self::assertFalse(BikeSeedMode::Update->marks());
    }

    public function testAFirstSeedIndexesAndADryRunDoesNot(): void
    {
        self::assertTrue(BikeSeedMode::Seed->indexes());
        self::assertFalse(BikeSeedMode::DryRun->indexes());
    }
}
