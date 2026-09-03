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
 * The reported failure, end to end.
 *
 * Staging, 2026-09-03. A shopper asked for *"a helmet with a cat print"* and the assistant replied:
 *
 * > "The search found no helmets with a cat print, though it did return **Chain Wear Indicator**,
 * > which matched 'cat print' but is not a helmet."
 *
 * Two separate things went wrong and only the first one is this file's business: the search returned
 * a chain gauge because `cat` is inside "Indi**cat**or". See {@see \Swag\AssistantStarterKit\Core\Retrieval\WordBoundaryMatch}
 * for the mechanism, and `MatchReasonsTest` for the second half — why the shopper was told about it.
 *
 * The second test here is the guard that matters more than the fix: the relaxations this pipeline
 * already depends on must keep working, because a filter that also swallowed "gloves" -> "glove"
 * would trade one bad answer for a worse one.
 */
final class SearchProductsFragmentMatchTest extends TestCase
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
        $this->catalogue = tempnam(sys_get_temp_dir(), 'fragment') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [
                        [
                            'id' => 'chainwearindicator00000000000001',
                            'name' => 'Chain Wear Indicator',
                            'description' => 'A drop-in gauge that shows when a chain has stretched past service.',
                            'price' => 14.90,
                            'stock' => 28,
                            'url' => '/p/chain-wear-indicator',
                            'categoryPath' => ['Maintenance', 'Tools'],
                            'properties' => ['Material' => ['Steel']],
                            'variants' => [],
                        ],
                        [
                            'id' => 'commuterhelmet000000000000000001',
                            'name' => 'Commuter Helmet',
                            'description' => 'An urban helmet with a rear light mount.',
                            'price' => 79.00,
                            'stock' => 5,
                            'url' => '/p/commuter-helmet',
                            'categoryPath' => ['Helmets'],
                            'properties' => ['Material' => ['Polycarbonate']],
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
     * The shop has no cat print and the reply must say so. What it must not do is hand over the one
     * product whose name happens to contain the letters.
     */
    public function testAFragmentMatchIsNotOfferedAsAHit(): void
    {
        $result = $this->tool()(term: 'cat print');

        self::assertSame([], self::ids($result), 'the Chain Wear Indicator matched only "indiCATor"');
        self::assertSame(0, $result['total']);
        self::assertArrayHasKey('note', $result, 'an empty search has to say it came up empty');
    }

    /**
     * The relaxation chain is load-bearing — a colleague was told the shop carries no gloves — so a
     * plural must still reach the singular product through it.
     */
    public function testTheRelaxationStillReachesTheSingularProduct(): void
    {
        $result = $this->tool()(term: 'helmets');

        self::assertSame(['commuterhelmet000000000000000001'], self::ids($result));
    }

    /** An ordinary whole-word search is untouched. */
    public function testAWholeWordSearchStillMatches(): void
    {
        $result = $this->tool()(term: 'chain');

        self::assertSame(['chainwearindicator00000000000001'], self::ids($result));
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
