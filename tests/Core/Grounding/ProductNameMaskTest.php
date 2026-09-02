<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\ProductNameMask;

/** A product's name is a label the shop chose, not a claim about its attributes. */
final class ProductNameMaskTest extends TestCase
{
    public function testAProductNameIsBlankedOut(): void
    {
        $masked = ProductNameMask::strip('Das Trail Jersey ist ausverkauft.', ['id-1' => 'Trail Jersey']);

        self::assertStringNotContainsString('Trail', $masked);
        self::assertStringContainsString('ausverkauft', $masked);
    }

    /** What the name does not cover is still there to be audited. */
    public function testAClaimBesideTheNameSurvives(): void
    {
        $masked = ProductNameMask::strip('Das Trail Jersey ist aus Merino.', ['id-1' => 'Trail Jersey']);

        self::assertStringContainsString('Merino', $masked);
    }

    /** Longest first, so a short name cannot blank the middle of a longer one. */
    public function testAShortNameDoesNotEatALongerOne(): void
    {
        $masked = ProductNameMask::strip('Wet Chain Lube 100ml is best.', [
            'id-1' => 'Chain',
            'id-2' => 'Wet Chain Lube 100ml',
        ]);

        self::assertStringNotContainsString('Chain', $masked);
        self::assertStringContainsString('is best', $masked);
    }

    public function testProseWithoutAnyNameIsUnchanged(): void
    {
        self::assertSame('Nothing to see here.', ProductNameMask::strip('Nothing to see here.', [
            'id-1' => 'Trail Jersey',
        ]));
    }
}
