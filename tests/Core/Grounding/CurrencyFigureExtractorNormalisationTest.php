<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\CurrencyFigureExtractor;

/**
 * Split out of {@see CurrencyFigureExtractorTest} (too-many-methods) rather than
 * suppressed: every test here is about normalising or collapsing what the pattern
 * matched — multiple figures in one string, thousands grouping, duplicates — as
 * opposed to that file's "does this one form get extracted at all" tests.
 */
final class CurrencyFigureExtractorNormalisationTest extends TestCase
{
    private function extractor(): CurrencyFigureExtractor
    {
        return new CurrencyFigureExtractor();
    }

    public function testExtractsBothFiguresFromADiscountStatementWithNoCurrencyToken(): void
    {
        $figures = $this->extractor()->extract('Now 12.90 (90% off 129.00).');

        sort($figures);
        self::assertSame(['12.90', '129.00'], $figures);
    }

    public function testDoesNotTruncateAThousandsGroupedFigureAtTheGroupSeparator(): void
    {
        // The pre-fix pattern's greedy [0-9]+ stopped at the first non-digit, so
        // "€1,299.00" was misread as "1.29" — a false positive the other way:
        // correct prose flagged as if it stated a different, smaller price.
        self::assertSame(['1299.00'], $this->extractor()->extract('The frame is €1,299.00.'));
    }

    public function testDoesNotTruncateAEuropeanStyleThousandsGroupedFigure(): void
    {
        self::assertSame(['1299.00'], $this->extractor()->extract('The frame is €1.299,00.'));
    }

    public function testDeduplicatesTheSameFigureAppearingTwice(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('It is €1.29. Yes, really, €1.29.'));
    }
}
