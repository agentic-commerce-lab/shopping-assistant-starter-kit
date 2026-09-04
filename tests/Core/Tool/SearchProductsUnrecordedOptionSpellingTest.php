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
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * How a group is SPELLED, split out of {@see SearchProductsUnrecordedOptionTest} — which holds the
 * two shapes that reach the disclosure and the guard against naming a group the products do record.
 *
 * The split is mago's `too-many-methods` and also the right seam: everything there is about *which*
 * groups may be disclosed, and everything here is about matching two spellings of the same one.
 *
 * Reported by review, 2026-09-04, and it made the tool state the one thing
 * {@see \Swag\AssistantStarterKit\Core\Tool\UnrecordedOptions}'s own docblock says it must never
 * state. {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver}
 * canonicalises an option's VALUE against the facet's own values but takes the GROUP name as exact —
 * its docblock says so — and {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet::get()}
 * compares fields with `===`. So `["season", "Summer"]` matched no `properties.season` facet, the
 * clause was dropped, the canonical selection kept the model's lowercase spelling, and an exact
 * comparison against the cards' `Season` key reported a group every returned tyre records:
 *
 *     options_not_recorded: ["season"]
 *     note: "No product below records season — not one of them lists it. You MAY say that plainly…"
 *
 * about two tyres that each list one. `testAGroupTheProductsDoRecordIsNotNamed` is one letter's case
 * away from this, which is why the comparison is normalised rather than exact.
 *
 * The catalogue here is the tyres alone: no `Colour` facet is needed, because every case in this
 * file is about a group the returned products DO record.
 */
final class SearchProductsUnrecordedOptionSpellingTest extends TestCase
{
    private string $catalogue = '';

    private TraceRecorder $trace;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    protected function setUp(): void
    {
        $this->catalogue = tempnam(sys_get_temp_dir(), 'spelling') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [
                        [
                            'id' => 'roadtyre28c000000000000000000001',
                            'name' => 'Road Tyre 28c',
                            'description' => 'A fast-rolling tyre for tarmac.',
                            'price' => 38.00,
                            'stock' => 12,
                            'url' => '/p/road-tyre-28c',
                            'categoryPath' => ['Tyres'],
                            'properties' => ['Season' => ['Summer'], 'Terrain' => ['Road']],
                            'variants' => [],
                        ],
                        [
                            'id' => 'touringtyre00000000000000000001',
                            'name' => 'Touring Tyre',
                            'description' => 'A reflective tyre for commuting.',
                            'price' => 44.00,
                            'stock' => 7,
                            'url' => '/p/touring-tyre',
                            'categoryPath' => ['Tyres'],
                            'properties' => ['Season' => ['All-season'], 'Terrain' => ['Road']],
                            'variants' => [],
                        ],
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    /**
     * The same guard, with the group spelled the way a model actually spells it.
     *
     * Reported by review, 2026-09-04. {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver}
     * canonicalises the option VALUE against the facet's own values but takes the GROUP name as
     * exact — its own docblock says so — so `["season", "Summer"]` finds no `properties.season`
     * facet, `QueryBuilder` drops the clause, and the canonical selection keeps the model's
     * lowercase spelling. `of()` then compared "season" against the cards' `Season` key, found no
     * match, and licensed the model to tell a shopper *"these tyres have no season recorded"* about
     * two tyres that each list one.
     *
     * That is the exact claim
     * {@see SearchProductsUnrecordedOptionTest::testAGroupTheProductsDoRecordIsNotNamed} exists to
     * forbid, reachable by changing one letter's case — so the comparison is normalised rather
     * than exact.
     */
    public function testAGroupTheProductsDoRecordIsNotNamedWhateverItsCase(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['season', 'Winter']]);

        self::assertNotSame([], self::ids($result));
        self::assertArrayNotHasKey('options_not_recorded', $result);
    }

    /**
     * And the same for whitespace and punctuation, which is the other way two spellings of one
     * group differ — a model echoing a group back out of prose picks up a stray space, and a shop
     * that writes `Shoe Size` gets asked about `shoe-size`. Folding case alone would still have
     * claimed no tyre records a terrain while both of them list `Road`.
     */
    public function testASpacingOrPunctuationDifferenceIsNotANewGroup(): void
    {
        $result = $this->tool()(term: 'tyre', options: [['terrain ', 'Gravel']]);

        self::assertNotSame([], self::ids($result));
        self::assertArrayNotHasKey('options_not_recorded', $result);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        /** @var list<array{id: string}> $products */
        $products = $result['products'] ?? [];

        return array_map(static fn(array $product): string => $product['id'], $products);
    }

    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);
        $this->trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            new AssistantConfig(),
        );
    }
}
