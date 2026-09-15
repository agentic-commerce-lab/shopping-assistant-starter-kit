<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\RelaxedTermRetry;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The two relaxation steps as the gateway sees them, and the term that comes back with the cards.
 *
 * The step count is not the whole contract. {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass}
 * judges whatever comes back with {@see \Swag\AssistantStarterKit\Core\Retrieval\RetainedCards}, and
 * judging it against a term the gateway never searched compares a card list to the wrong question.
 * With one step the caller could recompute the term itself; with two it cannot, because which step
 * produced the list is knowable only inside the retry.
 */
final class RelaxedTermRetryStepsTest extends TestCase
{
    private function query(string $term): ProductQuery
    {
        return new ProductQuery(term: $term, filters: [], limit: 5, sort: null, candidateLimit: 50, categoryId: null);
    }

    private function card(string $name): ProductCard
    {
        return new ProductCard(
            id: md5($name),
            parentId: null,
            name: $name,
            description: null,
            price: 9.90,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . md5($name),
            imageUrl: null,
        );
    }

    /**
     * The measured German case: one character finds nothing, two reaches the singular — and the
     * caller is told it was two.
     */
    public function testTheSecondStepRunsAndReportsItsOwnTerm(): void
    {
        $gateway = new RecordingTermGateway(['Anlassermotor' => [$this->card('ONE Anlassermotor')]]);

        $result = RelaxedTermRetry::search(
            $gateway,
            $this->query('Anlassermotoren'),
            new CatalogScope(),
            new TraceRecorder(),
        );

        self::assertNotNull($result);
        self::assertSame('Anlassermotor', $result['term']);
        self::assertCount(1, $result['cards']);
        self::assertSame(['Anlassermotore', 'Anlassermotor'], $gateway->termsSearched);
    }

    /**
     * The common case must not get more expensive: when one character is enough, two is never tried.
     */
    public function testOneCharacterThatWorksCostsExactlyOneRead(): void
    {
        $gateway = new RecordingTermGateway(['Batterie' => [$this->card('Varta Batterie')]]);

        $result = RelaxedTermRetry::search(
            $gateway,
            $this->query('Batterien'),
            new CatalogScope(),
            new TraceRecorder(),
        );

        self::assertNotNull($result);
        self::assertSame('Batterie', $result['term']);
        self::assertSame(['Batterie'], $gateway->termsSearched);
    }

    /**
     * Attempted and still empty is not the same as never attempted — `RetrievalPass` moves on to its
     * next relaxation in one case and skips a yield in the other.
     */
    public function testAttemptedAndEmptyReportsTheLastTermTried(): void
    {
        $gateway = new RecordingTermGateway([]);

        $result = RelaxedTermRetry::search(
            $gateway,
            $this->query('Weltraumraketen'),
            new CatalogScope(),
            new TraceRecorder(),
        );

        self::assertNotNull($result);
        self::assertSame([], $result['cards']);

        // The LAST step, not the first: both were tried and both came back empty, so the term the
        // empty result belongs to is the shorter one.
        self::assertSame('Weltraumraket', $result['term']);
        self::assertSame(['Weltraumrakete', 'Weltraumraket'], $gateway->termsSearched);
    }

    /**
     * Nothing long enough to shorten means no read at all, as before.
     */
    public function testNothingToRelaxTouchesTheGatewayNotAtAll(): void
    {
        $gateway = new RecordingTermGateway([]);

        self::assertNull(RelaxedTermRetry::search(
            $gateway,
            $this->query('rot'),
            new CatalogScope(),
            new TraceRecorder(),
        ));
        self::assertSame([], $gateway->termsSearched);
    }
}
