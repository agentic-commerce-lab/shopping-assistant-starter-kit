<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Tool\GivenDescriptions;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Asking about ONE product hands over that product's own prose.
 *
 * Measured on staging, 2026-09-03. A shopper asked how long the Front Light 800 runs before the
 * battery is empty. That product's description says *"Four hours on full, twelve on the commute
 * setting"* — and the assistant answered *"The shop's data for the Front Light 800 does not include
 * battery life"*, twice. True of what it had been given, false of the shop: descriptions reached the
 * model only through `CompareProductsTool`, so the one path a shopper uses to ask about a single
 * product was the one path that withheld the answer.
 *
 * The turn before it was worse — *"the shop shows the current figures … including its battery life"* —
 * a promise about content the model could not see either way.
 */
final class GetProductDescriptionTest extends TestCase
{
    private string $catalogue = '';

    protected function setUp(): void
    {
        $this->catalogue = tempnam(sys_get_temp_dir(), 'getproduct') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [[
                        'id' => 'frontlight800000000000000000000',
                        'name' => 'Front Light 800',
                        'description' =>
                            '800 lumen USB-C front light with a shaped beam. '
                                . 'Four hours on full, twelve on the commute setting.',
                        'price' => 54.90,
                        'stock' => 6,
                        'url' => '/p/front-light-800',
                        'categoryPath' => ['Lights'],
                        'properties' => ['Mounting' => ['Handlebar'], 'Weather protection' => ['Waterproof']],
                        // The fixture gateway indexes families, so a product needs its variant list even
                        // when it has exactly one sellable unit.
                        'variants' => [],
                    ]],
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

    public function testTheDescriptionReachesTheModel(): void
    {
        ['products' => $products] = $this->ask();

        self::assertCount(1, $products);

        $product = reset($products);
        self::assertIsArray($product);
        self::assertArrayHasKey('description', $product, 'get_product used to withhold this');
        self::assertStringContainsString('Four hours on full', (string) $product['description']);
    }

    /**
     * Recorded, or `ProseAudit` cannot tell a claim the shop's own text backs from an invented one —
     * and the reply would earn a correction note for repeating what the shop says.
     */
    public function testWhatWasHandedOverIsRecordedForTheAudit(): void
    {
        $trace = new TraceRecorder();
        $this->ask($trace);

        $given = GivenDescriptions::from($trace);

        self::assertCount(1, $given);

        $handed = reset($given);
        self::assertIsString($handed);
        self::assertStringContainsString('twelve on the commute setting', $handed);
    }

    /**
     * @return array{products: list<array<string, mixed>>, total: int, note?: string}
     */
    private function ask(?TraceRecorder $trace = null): array
    {
        $trace ??= new TraceRecorder();
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);

        $tool = new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );

        /** @var array{products: list<array<string, mixed>>, total: int, note?: string} $result */
        $result = $tool('frontlight800000000000000000000');

        return $result;
    }
}
