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
use Swag\AssistantStarterKit\Core\Tool\GivenDescriptions;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ShortlistDescriptions;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * `search_products` hands over descriptions when — and only when — the match set is a shortlist.
 *
 * Counted out of the trace export of 34 real conversations: **60 of 67 tool calls were
 * `search_products`**, so `descriptions.given` fired on **3 of 104 turns**, and **six of the eight
 * replies carrying an invented product fact came from conversations that never received a
 * description at all**. A shopper asked what a product was made of, or what came in the box, about
 * something a search had returned — and the model had been handed a name and some option values.
 *
 * Enriching the catalogue cannot reach those six: the text was never delivered. This is the delivery.
 *
 * See {@see ShortlistDescriptions} for why the gate is on `survivors` rather than on how many cards
 * came back, and for the measured reason the bound is three.
 */
final class SearchShortlistDescriptionsTest extends TestCase
{
    private string $catalogue = '';
    private TraceRecorder $trace;
    private FactRenderer $renderer;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    protected function setUp(): void
    {
        $products = [
            // Two of these, so a search for "sleeved" is a shortlist.
            $this->product(
                'sleeved90',
                'Sleeved Chain Lock 90cm',
                'A 90 cm hardened steel chain in a fabric sleeve, supplied with two keys and no bracket.',
            ),
            $this->product(
                'sleeved120',
                'Sleeved Chain Lock 120cm',
                'The same sleeved chain at 120 cm, supplied with two keys and no bracket.',
            ),
        ];

        // Five of these, so a search for "widget" is not.
        foreach (range(1, 5) as $n) {
            $products[] = $this->product(
                'widget' . $n,
                'Widget Number ' . $n,
                'Widget number ' . $n . ' is made of alloy.',
            );
        }

        $this->catalogue = tempnam(sys_get_temp_dir(), 'shortlist') . '.json';
        file_put_contents($this->catalogue, json_encode(['products' => $products], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    /**
     * The finding this whole change exists for: a shopper asking what the lock is made of, having
     * narrowed to two, now has the shop's own sentence in front of the model.
     */
    public function testAShortlistCarriesTheProductsOwnProse(): void
    {
        $result = $this->search('sleeved');

        self::assertCount(2, $result['products'], 'the fixture is built so this is a shortlist');

        foreach ($result['products'] as $product) {
            self::assertArrayHasKey('description', $product, 'search used to withhold this on every path');
        }

        $handed = GivenDescriptions::from($this->trace);

        self::assertCount(2, $handed);
        self::assertStringContainsString(
            'supplied with two keys and no bracket',
            implode(' ', $handed),
            'the sentence that refutes the invented bracket has to be the sentence handed over',
        );
    }

    /**
     * A broad search is where the unconditional version would have been paid for: the shopper has
     * chosen nothing, and five descriptions ride along on every browse.
     */
    public function testABroadResultStillCarriesNoProse(): void
    {
        $result = $this->search('widget');

        self::assertGreaterThan(
            ShortlistDescriptions::MAX_SURVIVORS,
            $result['matched'],
            'the fixture is built so this is not a shortlist',
        );

        foreach ($result['products'] as $product) {
            self::assertArrayNotHasKey('description', $product);
        }

        self::assertSame([], GivenDescriptions::from($this->trace));
    }

    /**
     * **Zero is an empty result, not a shortlist of nothing.** Recording the stage here would put an
     * empty hand-over in the trace for every failed search, and `GivenDescriptions` would then be
     * reading "the server supplied no text" out of events that mean "there was no product".
     */
    public function testAnEmptyResultRecordsNoHandover(): void
    {
        $this->search('nothingmatchesthis');

        self::assertSame([], GivenDescriptions::from($this->trace));
        self::assertNotContains(GivenDescriptions::STAGE, array_map(
            static fn($event) => $event->stage,
            $this->trace->events(),
        ));
    }

    /**
     * @return array{id: string, name: string, description: string, price: float, stock: int, url: string, categoryPath: list<string>, properties: array<string, list<string>>, variants: list<array<string, mixed>>}
     */
    private function product(string $slug, string $name, string $description): array
    {
        return [
            'id' => str_pad($slug, 32, '0'),
            'name' => $name,
            'description' => $description,
            'price' => 44.0,
            'stock' => 5,
            'url' => '/p/' . $slug,
            'categoryPath' => ['Accessories', 'Locks'],
            'properties' => ['Material' => ['Steel']],
            // The fixture gateway indexes families, so a product needs its variant list even when it
            // has exactly one sellable unit.
            'variants' => [],
        ];
    }

    /**
     * @return array{products: list<array<string, mixed>>, matched: int, total: int}
     */
    private function search(string $term): array
    {
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);

        $tool = new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            $this->renderer,
            $this->trace,
            new AssistantConfig(),
        );

        /** @var array{products: list<array<string, mixed>>, matched: int, total: int} $result */
        $result = $tool($term);

        return $result;
    }
}
