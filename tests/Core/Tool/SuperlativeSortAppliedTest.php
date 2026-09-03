<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

/**
 * The half the price-ordering mechanism was missing: the model passes no sort at all.
 *
 * **Measured on a 6.6 test shop, 2026-09-03, gemini-3.7-flash.** Asked "what is the cheapest coat?",
 * three samples running named a coat at 155.71 beside rendered cards of 155.71 / 71.07 / 135.42, with
 * `filtersApplied: []` and no sort every time. `SearchPriceSortTest` was green throughout — the
 * ordering worked, nothing made it run, because the parameter's use was documented only in the tool's
 * own docblock, which is prose addressed to a model.
 *
 * Nor did any audit catch it: the claim names a product and states no figure, and `ProseAudit` checks
 * figures and availability. The shopper saw a wrong superlative beside the card that disproved it.
 *
 * The shop reads the superlative itself now — see
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\SuperlativeSort}.
 */
final class SuperlativeSortAppliedTest extends PriceSortTestCase
{
    public function testTheShoppersOwnSuperlativeSortsWhenTheModelPassesNoSort(): void
    {
        self::assertSame(
            $this->names('price_asc'),
            $this->names(null, 'what is the cheapest jersey?'),
            'the shopper asked for it, so the shop applies it',
        );
    }

    /** An explicit argument still wins: a model asking for something else is answering another question. */
    public function testAnExplicitSortBeatsTheShoppersWords(): void
    {
        self::assertSame($this->names('price_desc'), $this->names('price_desc', 'what is the cheapest jersey?'));
    }

    /** A comparative is a different question, and must not reorder anything. */
    public function testAComparativeLeavesTheRankingAlone(): void
    {
        self::assertSame($this->names(null), $this->names(null, 'do you have anything cheaper?'));
    }
}
