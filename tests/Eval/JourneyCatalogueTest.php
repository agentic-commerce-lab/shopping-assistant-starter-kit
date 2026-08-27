<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\JourneyCatalogue;

/**
 * `any` is the default because every journey written before this field existed was written against the
 * small catalogue and re-verified against the large one — spec decision O10 keeps that true for the
 * fashion catalogue too, by copying the twelve real products into it verbatim.
 *
 * A journey that names a catalogue is making a claim it could not otherwise make, and a typo in that
 * claim must be loud — the same reason `AssertionRegistry`'s default arm throws rather than skipping.
 */
final class JourneyCatalogueTest extends TestCase
{
    public function testAJourneyThatNamesNoCatalogueRunsAgainstAllOfThem(): void
    {
        $catalogue = JourneyCatalogue::parse(null, 'some_journey');

        self::assertSame('any', $catalogue->name());
        self::assertTrue($catalogue->requires('small'));
        self::assertTrue($catalogue->requires('large'));
        self::assertTrue($catalogue->requires('fashion'));
    }

    public function testAJourneyThatNamesOneRunsOnlyAgainstThatOne(): void
    {
        $catalogue = JourneyCatalogue::parse('fashion', 'some_journey');

        self::assertSame('fashion', $catalogue->name());
        self::assertTrue($catalogue->requires('fashion'));
        self::assertFalse($catalogue->requires('small'));
        self::assertFalse($catalogue->requires('large'));
    }

    public function testAnExplicitAnyIsTheSameAsSayingNothing(): void
    {
        self::assertSame('any', JourneyCatalogue::parse('any', 'some_journey')->name());
        self::assertTrue(JourneyCatalogue::parse('any', 'some_journey')->requires('large'));
    }

    public function testAnUnknownCatalogueNameThrowsAndNamesTheTypo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fashon/');

        JourneyCatalogue::parse('fashon', 'some_journey');
    }

    public function testANonStringThrowsRatherThanBeingCoerced(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JourneyCatalogue::parse(['fashion'], 'some_journey');
    }
}
