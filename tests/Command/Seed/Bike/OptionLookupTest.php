<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\OptionLookup;
use Swag\AssistantStarterKit\Command\Seed\Bike\UnresolvedReferences;

/**
 * Resolving a `group => values` map against the shop's option ids — the one operation both a
 * product's variant axes and its descriptive properties need.
 *
 * Its own class because {@see \Swag\AssistantStarterKit\Command\Seed\Bike\ProductReferences} held two
 * copies of this double loop once properties arrived, which mago reported as complexity and jscpd
 * would have reported as duplication. Both were right: variants and properties differ in where the
 * map comes from and what the caller does with the result, never in how a value becomes an id.
 */
final class OptionLookupTest extends TestCase
{
    public function testEveryValueBecomesAResolvedOptionReference(): void
    {
        $unresolved = new UnresolvedReferences();

        $resolved = OptionLookup::resolve(
            ['Season' => ['Winter'], 'Material' => ['Merino', 'Nylon']],
            ['Season' => ['Winter' => 'id-winter'], 'Material' => ['Merino' => 'id-merino', 'Nylon' => 'id-nylon']],
            $unresolved,
            'property',
        );

        self::assertSame([['id' => 'id-winter'], ['id' => 'id-merino'], ['id' => 'id-nylon']], $resolved);
        self::assertTrue($unresolved->isEmpty());
    }

    /**
     * Null, not a shortened list. A partial result is the failure this whole file exists to prevent:
     * the product would be written looking correct while missing exactly the attribute somebody added
     * it for.
     */
    public function testOneMissingValueFailsTheWholeMap(): void
    {
        $unresolved = new UnresolvedReferences();

        $resolved = OptionLookup::resolve(
            ['Season' => ['Winter', 'Summer']],
            ['Season' => ['Winter' => 'id-winter']],
            $unresolved,
            'property',
        );

        self::assertNull($resolved);
        self::assertStringContainsString('property "Season = Summer"', $unresolved->describe());
    }

    /**
     * All of them, so one run over the catalogue reports every miss instead of one per invocation.
     */
    public function testEveryMissingValueIsRecordedNotJustTheFirst(): void
    {
        $unresolved = new UnresolvedReferences();

        OptionLookup::resolve(['Season' => ['Winter', 'Summer']], [], $unresolved, 'option');

        self::assertSame(2, $unresolved->count());
        self::assertStringContainsString('option "Season = Winter"', $unresolved->describe());
        self::assertStringContainsString('option "Season = Summer"', $unresolved->describe());
    }

    /**
     * An empty map resolves to an empty list, not to null: a product with no variant axes is a single
     * unit, which is a legitimate product and not a resolution failure.
     */
    public function testAnEmptyMapResolvesToAnEmptyList(): void
    {
        self::assertSame([], OptionLookup::resolve([], [], new UnresolvedReferences(), 'option'));
    }
}
