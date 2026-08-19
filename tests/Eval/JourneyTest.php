<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\Journey;

/**
 * Not in the brief's file list — added because {@see Journey::fromFile()}'s two most
 * important guarantees ("a typo in a journey fails loudly" and "the archetype/turns
 * defaulting is exactly as documented") are load-bearing enough to deserve a committed
 * regression test rather than a one-off manual check. See task-13-report.md.
 */
final class JourneyTest extends TestCase
{
    public function testLoadsARealJourneyFile(): void
    {
        $journey = Journey::fromFile(__DIR__ . '/../Journeys/price_constraint.php');

        self::assertSame('price_constraint', $journey->id);
        self::assertSame('grounding', $journey->category);
        self::assertSame(3, $journey->runs);
        self::assertArrayHasKey('price_matches_source', $journey->assertions);
        self::assertArrayHasKey('no_invented_product', $journey->assertions);
        self::assertArrayHasKey('no_unbacked_price_in_prose', $journey->assertions);
    }

    public function testDefaultsTurnsToASingleArchetypeTurnWhenOmitted(): void
    {
        $journey = Journey::fromFile(__DIR__ . '/../Journeys/variant_stock.php');

        self::assertSame(['archetype'], $journey->turns);
    }

    public function testThrowsOnAnUnknownAssertionNameInsteadOfSkippingItSilently(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'journey_');
        self::assertIsString($path);
        $path .= '.php';

        file_put_contents($path, <<<'PHP'
            <?php
            declare(strict_types=1);
            return [
                'id' => 'broken_journey',
                'category' => 'grounding',
                'runs' => 3,
                'archetypes' => ['expert' => 'anything'],
                'config' => [],
                'assertions' => ['no_invented_produtc' => []],
            ];
            PHP);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/unknown assertion "no_invented_produtc"/');

            Journey::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testThrowsWhenNoAssertionsAreDeclared(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'journey_');
        self::assertIsString($path);
        $path .= '.php';

        file_put_contents($path, <<<'PHP'
            <?php
            declare(strict_types=1);
            return [
                'id' => 'empty_journey',
                'category' => 'grounding',
                'runs' => 3,
                'archetypes' => ['expert' => 'anything'],
                'config' => [],
                'assertions' => [],
            ];
            PHP);

        try {
            $this->expectException(\InvalidArgumentException::class);

            Journey::fromFile($path);
        } finally {
            unlink($path);
        }
    }
}
