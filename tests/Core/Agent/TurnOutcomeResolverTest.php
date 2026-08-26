<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What a turn is recorded as having done.
 *
 * The outcome is not a diagnostic: the Administration's trace list filters on it, and a merchant reads
 * it to decide whether a conversation went well. So a turn that answered a shopper's question must not
 * be recorded the same way as one that answered nothing.
 *
 * **That is what `shop_info_retrieved` fixes.** Measured on the lab shop: every correct answer drawn
 * from a shop document — the revocation period, the imprint, the shipping cost — was recorded as
 * `no_result`, because `no_result` meant "no product cards were rendered". That was accurate before
 * documents existed and became a lie the moment they did, and it is the kind of lie nobody notices
 * because the reply itself is right.
 *
 * The value says `retrieved` rather than `answered` deliberately: under R3a passages reach the model
 * even when none answers the question, and whether it used one is not something the server can know.
 */
final class TurnOutcomeResolverTest extends TestCase
{
    public function testAPassageGivenToTheModelIsNotRecordedAsNoResult(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 1));

        self::assertSame(TurnOutcomeResolver::SHOP_INFO_RETRIEVED, (new TurnOutcomeResolver())->outcome($trace, []));
    }

    /**
     * Nothing clearing the recall floor genuinely is no result.
     *
     * The tool ran, nothing came back, and the model was told to say so — the feature working.
     * Recording that as a retrieval would make the trace list useless for finding the questions a
     * merchant's documents cannot answer at all, which is the most useful thing it could show them.
     */
    public function testRetrievingNothingIsStillNoResult(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 0));

        self::assertSame('no_result', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testATurnThatRetrievedNothingAtAllIsNoResult(): void
    {
        self::assertSame('no_result', (new TurnOutcomeResolver())->outcome(new TraceRecorder(), []));
    }

    /** Cards are the stronger signal: they are grounded facts, and prose is not. */
    public function testRenderedCardsOutrankAShopInfoAnswer(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 2));

        self::assertSame('product_shown', (new TurnOutcomeResolver())->outcome($trace, [self::card()]));
    }

    /** Handing over to a human is what happened, whatever was retrieved on the way. */
    public function testEscalationOutranksAShopInfoAnswer(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 1));
        $trace->record('escalate', ['reason' => 'wants a human']);

        self::assertSame('escalated', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    /**
     * A multi-turn conversation shares one recorder, so an earlier turn's retrieval is still in it.
     *
     * Any accepted passage in the run counts: the alternative is reading only the last event, which
     * would record a two-turn conversation by whichever half happened to end it.
     */
    public function testAnyRetrievalWithPassagesInTheRunCounts(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 1));
        $trace->record('retrieve.shopinfo', self::retrieval(accepted: 0));

        self::assertSame(TurnOutcomeResolver::SHOP_INFO_RETRIEVED, (new TurnOutcomeResolver())->outcome($trace, []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function retrieval(int $accepted): array
    {
        return [
            'question' => 'How long do I have to return something?',
            'threshold' => SearchShopInfoTool::RECALL_MIN_SCORE,
            'scores' => [0.61, 0.44],
            'accepted' => $accepted,
            'ms' => 42,
            'passages' => $accepted > 0 ? ['You have fourteen days to withdraw.'] : [],
        ];
    }

    private static function card(): ProductCard
    {
        return new ProductCard(
            id: 'fx-026-blue-m',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 74.9,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/trail-jersey',
            imageUrl: null,
        );
    }
}
