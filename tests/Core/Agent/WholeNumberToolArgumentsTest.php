<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A shopper's budget is a whole number far more often than not — "about 50 euros", not
 * "about 50.0 euros" — and a model asked for a maximum price emits it the way JSON
 * represents it: as an integer.
 *
 * Measured on the local shop, 2026-08-31, in the two turns of one conversation where a
 * shopper stated a budget:
 *
 * ```
 * 298  tool.arguments.rejected  {"reason":"Data expected to be of type \"float\" (\"int\" given)."}
 * 300  tool.arguments.rejected  … five in a row
 * 309  understand               {"term":"bag","priceMax":null,…}
 * 310  query.build              {"filtersApplied":[], …}
 * ```
 *
 * The model retried the same integer five times, then dropped the argument entirely and
 * searched with no ceiling at all — and then presented the results as if the budget had
 * been honoured. Thirteen of the sixteen argument rejections in the whole local trace
 * history are this one cause.
 *
 * The rejection is not ours. `ToolCallArgumentResolver` denormalizes each argument
 * against the tool method's declared type, and `Serializer::denormalize()` takes a scalar
 * shortcut for `float` that is a strict `is_float()` — so a JSON `50` never reaches the
 * `?float $priceMax` parameter that would have accepted it under PHP's own coercion
 * rules. {@see \Swag\AssistantStarterKit\Core\Agent\MalformedToolArgumentRejection} then
 * does its job correctly and hands the model a retryable note, which is why this cost a
 * turn's tool-call budget instead of throwing.
 */
final class WholeNumberToolArgumentsTest extends TestCase
{
    use UsesCatalogFixture;

    private function bundle(): AssistantAgentFactory\Bundle
    {
        return AssistantAgentFactory::withCoreToolsOnly(new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        }))->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            true,
            new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );
    }

    /**
     * Through the REAL toolbox the factory builds, not a hand-assembled one: the defect was
     * never in a tool's own body — `SearchProductsTool` was never reached — so a test that
     * calls the tool directly cannot see it. Only a dispatch through the argument resolver
     * the shipped agent actually uses can.
     */
    public function testAWholeNumberPriceCeilingReachesTheSearchAsAFloat(): void
    {
        $bundle = $this->bundle();

        $bundle->toolbox->execute(new ToolCall('call-1', 'search_products', [
            'term' => 'bottle',
            // A JSON integer, exactly as a model emits "under 15 euros".
            'priceMax' => 15,
        ]));

        $rejections = array_values(array_filter(
            $bundle->trace->events(),
            static fn(TraceEvent $event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertSame([], $rejections, 'A whole-number price ceiling must not be rejected.');

        self::assertSame(15.0, $bundle->trace->payload('understand')['priceMax'] ?? null);
    }

    /**
     * The widening must not silently become "the ceiling was understood but never applied":
     * `understand` records the tool's own arguments, so it would report 15.0 even if the
     * filter were dropped afterwards. `query.build` is where the constraint becomes a query.
     */
    public function testAWholeNumberPriceCeilingBecomesAnAppliedPriceFilter(): void
    {
        $bundle = $this->bundle();

        $bundle->toolbox->execute(new ToolCall('call-1', 'search_products', [
            'term' => 'bottle',
            'priceMax' => 15,
        ]));

        $payload = $bundle->trace->payload('query.build');
        self::assertSame(['price'], $payload['filtersApplied'] ?? null);
        self::assertSame([], $payload['filtersDropped'] ?? null);
    }

    /**
     * The other half of the same guarantee. Widening every number would be worse than the
     * defect it fixes: `quantity` and `limit` are declared `int` precisely so a fractional
     * value is rejected rather than silently truncated, and "reject, never coerce" is what
     * makes that bound trustworthy.
     */
    public function testAFractionalQuantityIsStillRejectedRatherThanTruncated(): void
    {
        $bundle = $this->bundle();

        $bundle->toolbox->execute(new ToolCall('call-1', 'add_to_cart', [
            'variantId' => 'fx-026-blue-l',
            'quantity' => 2.5,
        ]));

        $rejections = array_values(array_filter(
            $bundle->trace->events(),
            static fn(TraceEvent $event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejections);
    }
}
