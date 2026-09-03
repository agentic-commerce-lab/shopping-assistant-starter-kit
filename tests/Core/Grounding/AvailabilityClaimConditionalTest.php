<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\AvailabilityClaimExtractor;

/**
 * An offer to check availability is not a claim about it.
 *
 * Split from `AvailabilityClaimExtractorQuietTest`, which was at the method limit — the same reason
 * `ProductNameIndex` was split off its own class. The question this asks is narrower than that
 * class's: not "is the assertion negated" but "is there an assertion at all".
 */
final class AvailabilityClaimConditionalTest extends TestCase
{
    private function extractor(): AvailabilityClaimExtractor
    {
        return new AvailabilityClaimExtractor();
    }

    /**
     * Measured on staging 2026-09-03. Refusing an invented discount code, the assistant offered
     * *"if you'd like, I'll check whether a specific product is available and what it costs"* — and
     * the audit flagged `is available`, putting a correction note under a reply that claimed nothing.
     * Nothing following `whether` is being asserted.
     */
    public function testOfferingToCheckWhetherSomethingIsAvailableIsNotAClaim(): void
    {
        self::assertSame(
            [],
            $this->extractor()->extract("I'll check whether a specific product is available and what it costs."),
        );
        self::assertSame([], $this->extractor()->extract('Ich schaue nach, ob das Trikot verfügbar ist.'));
    }

    /**
     * `if` is deliberately NOT in that list: this is a real claim, and a window wide enough to catch
     * the interrogative reading would swallow it.
     */
    public function testAConditionalOfferStillCarriesItsClaim(): void
    {
        self::assertNotSame([], $this->extractor()->extract('If you want the blue one, it is available.'));
    }
}
