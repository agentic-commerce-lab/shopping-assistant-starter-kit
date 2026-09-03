<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\WordBoundaryMatch;

/**
 * Which of a search's hits matched a word the shopper actually typed, rather than a fragment sitting
 * inside an unrelated one.
 *
 * ## The defect this exists for
 *
 * Reported from the staging shop, 2026-09-03. A shopper asked for *"a helmet with a cat print"* and
 * the assistant answered:
 *
 * > "The search found no helmets with a cat print, though it did return **Chain Wear Indicator**,
 * > which matched 'cat print' but is not a helmet."
 *
 * `cat` is inside "Indi**cat**or". {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder}
 * hands the term to `Criteria::setTerm()`, which on the product repository does NOT use Shopware's
 * product keyword index — it goes through the generic `EntityScoreQueryBuilder`, one `ContainsFilter`
 * per token, which is `name LIKE '%cat%'`. Measured against the local shop for proof:
 *
 * ```
 * probe --search="oat"  ->  Wrap Coat, Maxi Coat, Bodycon Coat, Tailored Coat, … (10 hits)
 * ```
 *
 * ## Why a word edge, and not a minimum token length
 *
 * Shopware's own product search already draws this line. `ProductSearchTermInterpreter::slop()`
 * builds `keyword LIKE 'ct%'` patterns and `reversed LIKE 'tc%'` patterns — prefixes of the keyword
 * and prefixes of its reverse. That is prefix-or-suffix, and never a strict infix. So the storefront
 * search box would not have returned the Chain Wear Indicator, and the assistant matching more
 * loosely than the shop it speaks for is the anomaly being removed here.
 *
 * A minimum token length was the other candidate and it leaks: `print` is five characters and sits
 * inside "s**print**er".
 *
 * Both edges count, because German compounds are why the suffix half exists — a shopper searching
 * `helm` should still reach a `Fahrradhelm`.
 */
final class WordBoundaryMatchTest extends TestCase
{
    /**
     * The reported case. `cat` sits inside "Indicator" at neither edge of the word, so it is not
     * evidence that this product answers anything the shopper typed.
     */
    public function testAFragmentInsideAnUnrelatedWordDoesNotKeepACard(): void
    {
        $kept = WordBoundaryMatch::keep([$this->card('cwi', 'Chain Wear Indicator')], 'cat print');

        self::assertSame([], $kept);
    }

    /** The shopper's own word, at the front of one: an ordinary match, in whatever case they typed. */
    public function testATokenOpeningAWordKeepsTheCard(): void
    {
        $cards = [$this->card('cwi', 'Chain Wear Indicator')];

        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep($cards, 'chain')));
        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep($cards, 'CHAIN')));
    }

    /**
     * The suffix half, and the reason it is not dropped: a German compound carries the word the
     * shopper typed at its end.
     */
    public function testATokenClosingAWordKeepsTheCard(): void
    {
        $kept = WordBoundaryMatch::keep([$this->card('h1', 'Fahrradhelm Pro')], 'helm');

        self::assertSame(['h1'], self::ids($kept));
    }

    /**
     * Fail open. The card is an allowlist and does not carry the product number, the EAN or the
     * manufacturer — all of which the DAL does search — so a card containing no trace of the term
     * matched a field this class cannot see, and dropping it would lose a real hit.
     */
    public function testACardShowingNoTraceOfTheTermIsKept(): void
    {
        $kept = WordBoundaryMatch::keep([$this->card('cwi', 'Chain Wear Indicator')], 'ferrolane');

        self::assertSame(['cwi'], self::ids($kept));
    }

    /** The description is read too: it is prose the shopper can see and match against. */
    public function testAWordInTheDescriptionKeepsTheCard(): void
    {
        $card = $this->card('cwi', 'Chain Wear Indicator', 'A drop-in gauge that shows when a chain has stretched.');

        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep([$card], 'gauge')));
    }

    /**
     * The multi-token shape of the report: one token is a fragment of an unrelated word and the
     * other is absent altogether, so nothing about this card answers the query.
     */
    public function testAFragmentIsNotRescuedByAnAbsentSecondToken(): void
    {
        $kept = WordBoundaryMatch::keep([$this->card('cwi', 'Chain Wear Indicator')], 'cat helmet');

        self::assertSame([], $kept);
    }

    /** One token matching at an edge is enough; the others need not match at all. */
    public function testOneTokenMatchingAtAnEdgeIsEnough(): void
    {
        $kept = WordBoundaryMatch::keep([$this->card('ch', 'Commuter Helmet')], 'cheap helmet');

        self::assertSame(['ch'], self::ids($kept));
    }

    /**
     * Nothing to judge with leaves the set alone. A term of only short words cannot be the reason
     * the gateway returned anything — Shopware drops those tokens before it searches — so this must
     * not become "return nothing".
     */
    public function testATermWithNothingToJudgeKeepsEverything(): void
    {
        $cards = [$this->card('cwi', 'Chain Wear Indicator')];

        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep($cards, null)));
        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep($cards, '')));
        self::assertSame(['cwi'], self::ids(WordBoundaryMatch::keep($cards, 'a of')));
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    private function card(string $id, string $name, ?string $description = null): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: $name,
            description: $description,
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
