<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\TermContribution;

/**
 * Which of a multi-term search's terms put nothing in front of the shopper.
 *
 * ## The defect this exists for
 *
 * Measured 2026-08-27 on the fashion catalogue, and found by re-running the same prompt on a second
 * model rather than by any assertion: `terms: ["Occasion Dresses", "Occasion Suits"]` returned eight
 * cards, **all of them dresses**, at every limit tried.
 *
 * The cause is retrieval, not the merge. `FixtureTermMatcher` fails its all-tokens pass for
 * "Occasion Suits" — "suits" is in no product name — and its any-token fallback then matches on
 * "occasion" alone, returning the identical ranked list the dress term returned. So the second term
 * contributed nothing that was not already there, and interleaving cannot invent a difference that
 * retrieval did not produce.
 *
 * What the tool CAN do is stop the model over-claiming. Told nothing, it writes "here are dresses and
 * suits" while only dresses render — which is the exact prose/cards mismatch multi-term search was
 * built to fix, arriving through a different door. Same discipline as `SearchProductsTool::NO_MATCH_NOTE`
 * and `TruncatedFamilies`: disclose what the reply does not contain rather than let silence read as
 * coverage.
 */
final class TermContributionTest extends TestCase
{
    public function testATermWhoseCardsAllSurvivedContributed(): void
    {
        $missing = TermContribution::termsWithoutResults([
            'dress' => [$this->card('d1')],
            'suit' => [$this->card('s1')],
        ], [$this->card('d1'), $this->card('s1')]);

        self::assertSame([], $missing);
    }

    /** The measured case: the second term's candidates were the first term's, so nothing of its own shows. */
    public function testATermThatContributedNothingIsNamed(): void
    {
        $missing = TermContribution::termsWithoutResults([
            'Occasion Dresses' => [$this->card('d1')],
            'Occasion Suits' => [$this->card('d1')],
        ], [$this->card('d1')]);

        self::assertSame(['Occasion Suits'], $missing);
    }

    public function testATermWhoseCardsWereAllNarrowedAwayIsNamed(): void
    {
        $missing = TermContribution::termsWithoutResults([
            'dress' => [$this->card('d1')],
            'suit' => [$this->card('s1')],
        ], [$this->card('d1')]);

        self::assertSame(['suit'], $missing);
    }

    /** A single-term search can never be one-sided, so it must never carry this disclosure. */
    public function testASingleTermIsNeverReported(): void
    {
        self::assertSame([], TermContribution::termsWithoutResults(['dress' => []], []));
        self::assertSame([], TermContribution::termsWithoutResults(['dress' => [$this->card('d1')]], []));
    }

    /** Nor may an empty result: `NO_MATCH_NOTE` already says the whole search found nothing. */
    public function testEveryTermFailingIsNotReportedTermByTerm(): void
    {
        self::assertSame([], TermContribution::termsWithoutResults(['dress' => [], 'suit' => []], []));
    }

    private function card(string $id): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Card ' . $id,
            description: null,
            price: 1.0,
            currency: 'EUR',
            stock: 1,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }
}
