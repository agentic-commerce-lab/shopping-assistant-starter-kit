<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedMode;

/**
 * The three answers a run needs before it touches anything: may it start, does it write, does it
 * mark.
 *
 * **Why this is a unit and not a branch inside the runner.** `SeedWriter` and `SeedCompletion` are
 * `final readonly`, so a runner test cannot substitute them and this repo has no integration test
 * that drives a real seed. The rule "an update may re-enter a seeded shop but must not re-mark it"
 * would therefore have shipped unverified — and it is precisely the rule whose silent breakage
 * doubles a catalogue. Keeping it here makes it checkable without a database.
 */
final class BikeSeedModeTest extends TestCase
{
    public function testAFirstSeedRefusesAShopThatAlreadyCarriesTheMarker(): void
    {
        self::assertTrue(BikeSeedMode::Seed->refusesASeededShop());
    }

    /**
     * The whole point of the mode. The marker means "this catalogue is here", not "these products are
     * final" — and because every id is derived from the product number
     * ({@see \Swag\AssistantStarterKit\Command\Seed\SeedId}), re-writing them is an update, not a
     * second catalogue.
     */
    public function testAnUpdateMayReEnterAShopThatAlreadyCarriesTheMarker(): void
    {
        self::assertFalse(BikeSeedMode::Update->refusesASeededShop());
    }

    public function testADryRunNeverRefusesBecauseItNeverWrites(): void
    {
        self::assertFalse(BikeSeedMode::DryRun->refusesASeededShop());
        self::assertFalse(BikeSeedMode::DryRun->writes());
    }

    public function testTheModeIsChosenFromTheTwoFlagsWithDryRunWinning(): void
    {
        self::assertSame(BikeSeedMode::Seed, BikeSeedMode::of(dryRun: false, update: false));
        self::assertSame(BikeSeedMode::Update, BikeSeedMode::of(dryRun: false, update: true));
        self::assertSame(BikeSeedMode::DryRun, BikeSeedMode::of(dryRun: true, update: false));
        // Both flags together is a dry run of an update: the safer reading of an ambiguous request.
        self::assertSame(BikeSeedMode::DryRun, BikeSeedMode::of(dryRun: true, update: true));
    }

    /**
     * An update on a shop that was never seeded is a mistake, not a first seed.
     *
     * It would write the catalogue with no marker and — because {@see BikeSeedMode::marks()} is false
     * — without completion, so the products would land unindexed and invisible to the assistant's own
     * search while looking present in the administration. That is a worse state than either a clean
     * refusal or a real first seed, and it is the state a mistyped flag produces.
     */
    public function testAnUpdateRequiresAShopThatWasAlreadySeeded(): void
    {
        self::assertTrue(BikeSeedMode::Update->requiresASeededShop());
        self::assertFalse(BikeSeedMode::Seed->requiresASeededShop());
        self::assertFalse(BikeSeedMode::DryRun->requiresASeededShop());
    }

    /**
     * The refusal message, so the operator is told which flag to reach for instead of being told no.
     *
     * Owned here rather than in the runner because the rule and its explanation are the same thing:
     * a reader who wants to know when a second run is allowed should not have to find the sentence in
     * one file and the condition in another.
     */
    public function testAFirstSeedOnAMarkedShopIsRefusedAndPointsAtTheUpdateFlag(): void
    {
        $refusal = BikeSeedMode::Seed->refusalFor(seeded: true);

        self::assertIsString($refusal);
        self::assertStringContainsString('--update', $refusal);
    }

    public function testAnUpdateOnAnUnmarkedShopIsRefusedAndSaysToSeedFirst(): void
    {
        $refusal = BikeSeedMode::Update->refusalFor(seeded: false);

        self::assertIsString($refusal);
        self::assertStringContainsString('without --update', $refusal);
    }

    /** Every combination that should be allowed to proceed, in one place. */
    public function testTheAllowedCombinationsAreNotRefused(): void
    {
        self::assertNull(BikeSeedMode::Seed->refusalFor(seeded: false));
        self::assertNull(BikeSeedMode::Update->refusalFor(seeded: true));
        self::assertNull(BikeSeedMode::DryRun->refusalFor(seeded: false));
        self::assertNull(BikeSeedMode::DryRun->refusalFor(seeded: true));
    }
}
