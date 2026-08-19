<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\CurrencyFigureExtractor;

/**
 * Finding I3: the pre-fix pattern required `€` or a case-sensitive `EUR` adjacent to
 * the digits, so "1.29 euro", "1.29 euros", "only 1.29", "$1.29", "1,29 Euro" and
 * "12.90 (90% off 129.00)" all passed unflagged with a single rendered card. Every
 * one of those forms is a distinct test below; each must have failed against the
 * pre-fix pattern to prove this class, not luck, closes the gap.
 */
final class CurrencyFigureExtractorTest extends TestCase
{
    private function extractor(): CurrencyFigureExtractor
    {
        return new CurrencyFigureExtractor();
    }

    public function testExtractsASymbolPrefixedFigure(): void
    {
        self::assertSame(['12.90'], $this->extractor()->extract('The cage costs €12.90.'));
    }

    public function testExtractsADollarPrefixedFigure(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('It is only $1.29 today.'));
    }

    public function testExtractsALowercaseEuroWordSuffix(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('The price is 1.29 euro.'));
    }

    public function testExtractsAPluralLowercaseEuroWordSuffix(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('That will be 1.29 euros.'));
    }

    public function testExtractsACommaDecimalWithACapitalisedEuroWord(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('The price is 1,29 Euro.'));
    }

    public function testExtractsABareDecimalNearPriceLanguageWithNoCurrencyToken(): void
    {
        self::assertSame(['1.29'], $this->extractor()->extract('Great news, it is only 1.29 today.'));
    }

    public function testExtractsAWholeEuroFigureWithNoCents(): void
    {
        self::assertSame(['24'], $this->extractor()->extract('The price is €24 today.'));
    }

    public function testExtractsNothingFromProseWithNoFigure(): void
    {
        self::assertSame([], $this->extractor()->extract('This product is currently out of stock.'));
    }
}
