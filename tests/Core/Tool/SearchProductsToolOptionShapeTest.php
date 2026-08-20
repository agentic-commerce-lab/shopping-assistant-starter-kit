<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The `options` shapes a live model actually emits, as opposed to the one the tool
 * schema documents.
 *
 * Measured against `anthropic/claude-sonnet-5` through `swag:assistant:probe --ask`
 * on 2026-08-20: asked "do you have the trail jersey in black, size M?" it sent
 *
 *     options = [["Colour","Black"],["Size","M"]]
 *
 * — a list of `[group, option]` pairs rather than the documented list of
 * `{"option": …, "group": …}` objects. Every run did it, on both the interrogative
 * and the imperative phrasing. The guard rejected each one, which cost a tool call
 * out of a budget of five and returned a note; the model's next attempt passed the
 * GROUP NAMES as option values (`[{"option":"Colour"},{"option":"Size"}]`), so both
 * filters were dropped and the whole seven-member family came back, costing another
 * call to narrow. That arithmetic is what ended turns as `tool_limit_exceeded` —
 * recorded as phrasing-sensitivity in both of the 2026-08-20 handoffs, which it is
 * not: the phrasing correlation was two samples of a per-call coin flip.
 *
 * So the tool boundary now accepts the isomorphic shapes and normalises them. This
 * is deliberately NOT the coercion {@see \Swag\AssistantStarterKit\Core\Tool\Guard}
 * refuses to do: a bound (length, count) coerced downwards is a silently weakened
 * limit, whereas `[["Colour","Black"]]` and `[{"option":"Black","group":"Colour"}]`
 * carry identical information and neither is more permissive than the other. What
 * stays a rejection is input that is genuinely ambiguous or absent.
 */
final class SearchProductsToolOptionShapeTest extends TestCase
{
    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }

    /** The shape measured from a live model, verbatim. */
    public function testResolvesTheGroupOptionPairListALiveModelActuallySends(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: [['Colour', 'Black'], ['Size', 'M']]);

        self::assertSame(['fx-026-black-m'], self::ids($result));
    }

    /** `{"Colour":"Black","Size":"M"}` — the other shape a JSON-schema-guessing model reaches for. */
    public function testResolvesAGroupToOptionMap(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: ['Colour' => 'Black', 'Size' => 'M']);

        self::assertSame(['fx-026-black-m'], self::ids($result));
    }

    /** `["Black","M"]` — bare option values, no groups. The group-less path already handles these. */
    public function testResolvesBareOptionStrings(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: ['Black', 'M']);

        self::assertSame(['fx-026-black-m'], self::ids($result));
    }

    /** The documented shape keeps working — this is a superset, not a replacement. */
    public function testStillResolvesTheDocumentedObjectShape(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: [
            ['option' => 'Black', 'group' => 'Colour'],
            ['option' => 'M', 'group' => 'Size'],
        ]);

        self::assertSame(['fx-026-black-m'], self::ids($result));
    }

    /** A tuple of three is not a (group, option) pair and must not be guessed at. */
    public function testRejectsAnAmbiguousLongerTuple(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessage('options');

        $this->tool()(term: 'Trail Jersey', options: [['Colour', 'Black', 'Size']]);
    }

    /**
     * Neither is a non-string scalar, which carries no option value at all.
     *
     * The argument is deliberately off-schema — that is the whole assertion — so the
     * analyser's complaint about it is the declaration doing its job, not a defect. A
     * live model reaches this path by sending a number where the schema says string;
     * nothing statically well-typed can.
     */
    // @mago-expect analysis:invalid-argument
    public function testRejectsANonStringEntry(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessage('options');

        $this->tool()(term: 'Trail Jersey', options: [42]);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        $products = $result['products'] ?? [];
        self::assertIsArray($products);

        $ids = [];
        foreach ($products as $product) {
            self::assertIsArray($product);
            $id = $product['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
