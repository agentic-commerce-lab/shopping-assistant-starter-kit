<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Assertion\NoInventedProduct;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyAttempt;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Ruling R42: a product invented in an EARLIER turn of a multi-turn run must still be
 * caught even though a LATER turn's own `validate` event is clean — because
 * {@see TraceRecorder::payload()} returns only the last event for a stage, and before
 * this fix {@see NoInventedProduct} read exactly that. No deterministic test exercised
 * {@see JourneyAttempt}'s actual multi-turn wiring at all before this one, so this
 * drives it end to end with a scripted two-turn conversation — reproducing the real bug
 * through the real code path, not just unit-testing the assertion's aggregation logic
 * against a hand-built trace (that coverage also exists, in NoInventedProductTest).
 */
final class JourneyAttemptMultiTurnTest extends TestCase
{
    use UsesCatalogFixture;

    public function testAnInventedProductFromAnEarlierTurnIsStillCaughtAfterALaterCleanTurn(): void
    {
        $http = new MockHttpClient([
            // Turn 1, response A: the model resolves the variant via get_product.
            new MockResponse(json_encode(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call-1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_product',
                                    'arguments' => json_encode(
                                        [
                                            'productId' => 'fx-026',
                                            'options' => [
                                                ['option' => 'Blue', 'group' => 'Colour'],
                                                ['option' => 'L', 'group' => 'Size'],
                                            ],
                                        ],
                                        \JSON_THROW_ON_ERROR,
                                    ),
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            )),
            // Turn 1, response B: the model's final text invents "fx-999", an id that
            // was never retrieved — this turn's own `validate` event must show it.
            new MockResponse(json_encode(
                [
                    'choices' => [[
                        'message' => ['content' => 'It is fx-026-blue-l, and fx-999 is also on sale.'],
                        'finish_reason' => 'stop',
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            )),
            // Turn 2, response A: the model adds the already-resolved variant to the cart.
            new MockResponse(json_encode(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call-2',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'add_to_cart',
                                    'arguments' => json_encode(
                                        [
                                            'variantId' => 'fx-026-blue-l',
                                            'quantity' => 1,
                                            // What a real model now sends: `add_to_cart` refuses a
                                            // variant of a family the shopper never named.
                                            'options' => [['Colour', 'Blue'], ['Size', 'L']],
                                        ],
                                        \JSON_THROW_ON_ERROR,
                                    ),
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            )),
            // Turn 2, response B: a clean final text with no product ids at all — this
            // turn's OWN `validate` event correctly shows nothing invented. Before R42,
            // this clean event is exactly what made turn 1's finding disappear.
            new MockResponse(json_encode(
                [
                    'choices' => [[
                        'message' => ['content' => 'Added it to your cart.'],
                        'finish_reason' => 'stop',
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            )),
        ]);

        $journey = Journey::fromFile(__DIR__ . '/../Journeys/cart_add.php');

        $attempt = new JourneyAttempt(
            new LlmSettings('https://1.1.1.1', 'k', 'gpt-x'),
            self::catalogFixturePath(),
            $http,
        );

        // cart_add's turns are literal strings, not 'archetype', so no phrase is needed.
        [$turn, $trace] = $attempt->run($journey, null);

        $result = (new NoInventedProduct())->evaluate($turn, $trace, []);

        self::assertFalse(
            $result->passed,
            'An id invented in turn 1 must still fail the assertion even though turn 2\'s own validate event is clean.',
        );
        self::assertStringContainsString('fx-999', $result->detail);
    }
}
