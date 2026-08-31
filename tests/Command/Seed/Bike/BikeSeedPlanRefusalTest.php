<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedPlan;

/**
 * What the plan does when the shop is not the shop the catalogue was written against.
 *
 * Split from {@see BikeSeedPlanTest} for mago's per-class method budget, and the two halves ask
 * genuinely different questions: that one checks the payloads a good plan produces, this one checks
 * that a bad one produces none at all. The second is the half that protects somebody's database.
 */
final class BikeSeedPlanRefusalTest extends TestCase
{
    /**
     * The load-bearing one. Every kind of unresolved reference is named in a single error, before
     * anything is written — not discovered one batch into a `create()` that cannot be rolled back.
     */
    public function testAnUnresolvableReferenceThrowsBeforeAnythingIsPlanned(): void
    {
        $empty = FakeShopTaxonomy::empty();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no write has started/');

        BikeSeedPlan::build($empty, FakeShopTaxonomy::SALES_CHANNEL_ID);
    }

    public function testTheErrorNamesWhatCouldNotBeResolved(): void
    {
        $empty = FakeShopTaxonomy::empty();

        try {
            BikeSeedPlan::build($empty, FakeShopTaxonomy::SALES_CHANNEL_ID);
            self::fail('Expected the plan to refuse an empty taxonomy.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Jerseys', $e->getMessage());
        }
    }
}
