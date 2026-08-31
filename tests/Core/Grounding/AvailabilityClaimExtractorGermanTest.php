<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\AvailabilityClaimExtractor;

/**
 * The German half of the detector, and why it is not optional.
 *
 * Ruling R75 is the failure this whole extractor exists for: a live turn answered *"Yes, the Trail
 * Jersey is available in Blue, size M"* beside a rendered card reporting **stock 0**. The class
 * closed it — in English, and its own docblock said so: *"English only, per D11."*
 *
 * Since the assistant now answers in the shopper's language rather than in English, that comment
 * described a control that was switched off for every German shopper. The identical sentence in
 * German passed unflagged, next to the identical card.
 *
 * **Both language sets run on every reply, unconditionally.** There is no per-turn language to
 * select on: the reply's language is decided by the model from the shopper's own words, not by a
 * setting the server could read beforehand. A missed claim is the expensive direction here, and
 * running an extra dozen alternatives costs nothing worth measuring.
 *
 * ## The one structural difference from English
 *
 * German negates a verb phrase from **behind**: *"wir führen das leider nicht"* asserts and then
 * withdraws, where the English equivalent puts *"don't"* in front of the verb. The preceding-window
 * check that is sufficient for every English pattern is blind to it, so the verb-first German
 * patterns are checked in both directions. The adjectival ones (*"ist verfügbar"*) negate from the
 * front like English and keep the single window — widening those would make an honest claim
 * disappear whenever the next clause happened to deny a different variant.
 */
final class AvailabilityClaimExtractorGermanTest extends TestCase
{
    private function extractor(): AvailabilityClaimExtractor
    {
        return new AvailabilityClaimExtractor();
    }

    /** Ruling R75's own sentence, in German. */
    public function testYesWeHaveItInGermanIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('Ja, wir haben das Trail Jersey in Blau, Größe M.'));
    }

    public function testIsAvailableInGermanIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('Das Trail Jersey ist in Blau verfügbar.'));
    }

    public function testInStockInGermanIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('Es ist auf Lager.'));
    }

    public function testDeliverableInGermanIsAClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('Der Artikel ist sofort lieferbar.'));
    }
}
