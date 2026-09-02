<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext;

/**
 * The cards from the previous turn, named so the model does not have to go looking for them again.
 *
 * ## The defect, measured on the staging shop 2026-09-02
 *
 * Turn 1 showed **Club Jersey (Blue, M)** `90d9b582…`. Turn 2 — *"add that to my cart"* — put
 * **Club Jersey (Red, XL)** `11c2bd10…` in the cart. Same parent, a variant the shopper had never
 * seen, and the reply announced it as though it were the one on screen.
 *
 * The cause is that only prose crosses a turn boundary (see
 * {@see \Swag\AssistantStarterKit\Core\Agent\ShopwareChatTurnRunner}'s `bag()`). The model reads
 * "Club Jersey" as a *name*, has no id, and must search again — and a search keyed on a different
 * term ranks the family's variants differently, so a different one comes back as its representative.
 *
 * ## Why this belongs in the system prompt and not in the conversation
 *
 * `bag()` argues, correctly, that re-injecting ids into the replayed conversation would put machine
 * tokens into shopper-facing context. The system prompt is not shopper-facing, and
 * {@see ViewingContext} already puts an id there for the product on screen. This is the same
 * mechanism pointed at the previous turn.
 *
 * ## The wording is load-bearing in both directions
 *
 * `ViewingContext`'s docblock records a measurement where one wrong clause cost 10.1 s by sending
 * the model back for a tool call it did not need. This line has the mirrored risk as well: too
 * insistent and the model answers about last turn's products when the shopper has moved on. So it
 * must say both halves — use these ids for "that", and search normally for anything else.
 */
final class RecentCardsContextTest extends TestCase
{
    public function testItNamesEachCardWithItsIdAndOptions(): void
    {
        $line = RecentCardsContext::line([
            self::card('90d9b582', 'Club Jersey', ['Colour' => 'Blue', 'Size' => 'M']),
            self::card('fc3104d7', 'Thermal Jersey', ['Colour' => 'Black', 'Size' => 'S']),
        ]);

        // The id is the whole point: without it the model searches by name and lands on a sibling.
        self::assertStringContainsString('90d9b582', $line);
        self::assertStringContainsString('fc3104d7', $line);
        self::assertStringContainsString('Colour: Blue', $line);
        self::assertStringContainsString('Size: M', $line);
    }

    /**
     * The saved round trip. Turn 2 measured at 19–40 s on staging, of which one model call plus one
     * `search_products` is a re-lookup of something the shop already had — so this clause is the
     * latency half of the fix, not a politeness.
     */
    public function testItTellsTheModelNotToLookThemUpAgain(): void
    {
        $line = RecentCardsContext::line([self::card('90d9b582', 'Club Jersey', ['Size' => 'M'])]);

        self::assertMatchesRegularExpression('/do not search|without searching|no need to search/i', $line);
    }

    /**
     * The other direction, and the one that cost 10.1 s the last time it was got wrong: a shopper
     * who has moved on must not be answered out of the previous turn's shortlist.
     */
    public function testItStillTellsTheModelToSearchForAnythingElse(): void
    {
        $line = RecentCardsContext::line([self::card('90d9b582', 'Club Jersey', ['Size' => 'M'])]);

        self::assertMatchesRegularExpression('/anything else|something else|a different/i', $line);
    }

    public function testNoCardsIsNoLine(): void
    {
        // Most turns are the first of their conversation. An empty clause on every one of them is
        // prompt tokens spent to say nothing.
        self::assertSame('', RecentCardsContext::line([]));
    }

    /**
     * The same rule {@see ViewingContext} is built around: the prompt carries no figures. A price
     * the model read in its own prompt is a price it can quote without earning it — and this one
     * would be a turn old, which is worse than merely unearned.
     */
    public function testItNeverCarriesAFigure(): void
    {
        $card = self::card('90d9b582', 'Club Jersey', ['Size' => 'M']);
        $line = RecentCardsContext::line([$card]);

        foreach (['74.9', '74,9', (string) $card->stock, $card->currency, 'in stock'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $line);
        }
    }

    /** @param array<string, string> $options */
    private static function card(string $id, string $name, array $options): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: 'parent-a',
            name: $name,
            description: 'A jersey.',
            price: 74.90,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }
}
