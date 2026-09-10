<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\BundlePriceNote;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Asking about one bundle, end to end, over the fixture catalogue.
 *
 * **`get_product` is the call a bundle question actually reaches.** Measured on staging 2026-09-10:
 * asked *"What is in the Drivetrain Care Bundle and how much do I save?"* the model called
 * `search_products`, then `get_product`, and it was `get_product` that returned the bundle's own
 * prose. A contents field wired into search alone would be missing from the one path that answers
 * questions about a single product — which is the shape of the bug this whole change fixes, since
 * `get_product` was already the path that had descriptions when search did not.
 *
 * **The fixture carries bundles so this is testable without a Commercial licence at all.** Bundles
 * are a licensed feature of a plugin that is not a dependency here, so without a fixture shape for
 * them the only way to exercise a bundle answer is a live Evolve-tier shop — which is how a feature
 * ends up with no test at all. `bundleItems` in the catalogue JSON is what lets the journey and eval
 * suites reach this behaviour later.
 */
final class GetProductBundleContentsTest extends TestCase
{
    private const BUNDLE_ID = 'drivetraincarebundle00000000000';

    private string $catalogue = '';

    protected function setUp(): void
    {
        $this->catalogue = tempnam(sys_get_temp_dir(), 'bundle') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [[
                        'id' => self::BUNDLE_ID,
                        'name' => 'Drivetrain Care Bundle',
                        // Says what the set is FOR and never enumerates it, which is the realistic case:
                        // Shopware renders the item list from `bundle_item`, so a merchant does not repeat
                        // it in prose. This is exactly the description the live bundle carries.
                        'description' => 'Keeps a drivetrain clean and says when the chain is finished.',
                        'price' => 73.57,
                        'stock' => 14,
                        'url' => '/p/drivetrain-care-bundle',
                        'categoryPath' => ['Drivetrain'],
                        'properties' => [],
                        'variants' => [],
                        'bundleItems' => [
                            ['name' => 'Dry Chain Lube 100ml', 'quantity' => 1, 'required' => true],
                            ['name' => 'Degreaser 500ml', 'quantity' => 1, 'required' => true],
                            ['name' => 'Cassette Removal Tool', 'quantity' => 1, 'required' => true],
                            ['name' => 'Chain Wear Indicator', 'quantity' => 1, 'required' => false],
                            ['name' => 'Bike Wash 1L', 'quantity' => 1, 'required' => false],
                        ],
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

    public function testTheModelIsToldWhatTheBundleContains(): void
    {
        $products = $this->ask()['products'] ?? [];
        self::assertIsArray($products);

        $product = reset($products);
        self::assertIsArray($product);
        self::assertSame(
            [
                ['name' => 'Dry Chain Lube 100ml'],
                ['name' => 'Degreaser 500ml'],
                ['name' => 'Cassette Removal Tool'],
                ['name' => 'Chain Wear Indicator', 'optional' => true],
                ['name' => 'Bike Wash 1L', 'optional' => true],
            ],
            $product['bundle'] ?? null,
        );
    }

    public function testTheReplyStatesWhatTheBundlePriceCovers(): void
    {
        $result = $this->ask();

        self::assertSame(BundlePriceNote::NOTE . ' ' . BundlePriceNote::OPTIONAL_ITEMS, $result['bundle_note'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(): array
    {
        $trace = new TraceRecorder();
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);

        $tool = new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );

        /** @var array<string, mixed> $result */
        $result = $tool(self::BUNDLE_ID);

        return $result;
    }
}
