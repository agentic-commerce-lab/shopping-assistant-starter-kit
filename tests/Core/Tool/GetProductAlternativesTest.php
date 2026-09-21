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
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * *"Do you have the trail jersey in blue, size M?"* reaching the tool that answers it.
 * {@see AvailableAlternativesTest} owns what an alternative may be; this owns whether the setting
 * decides it appears at all, and that it lands on the product it is about.
 */
final class GetProductAlternativesTest extends TestCase
{
    use UsesCatalogFixture;

    private function tool(bool $suggest): GetProductTool
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $trace = new TraceRecorder();

        return new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(suggestAlternatives: $suggest),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function blueM(bool $suggest): array
    {
        $result = $this->tool($suggest)(productId: 'fx-026', options: [['option' => 'Blue'], ['option' => 'M']]);

        return $result['products'][0] ?? [];
    }

    public function testWithTheSettingOnASoldOutVariantCarriesWhatCanBeBoughtInstead(): void
    {
        $product = $this->blueM(suggest: true);

        self::assertSame(true, $product['soldOut'] ?? null);
        self::assertSame(
            [
                ['Colour' => 'Blue', 'Size' => 'L'],
                ['Colour' => 'Black', 'Size' => 'M'],
            ],
            $product['alternatives'] ?? null,
        );
    }

    public function testWithTheSettingOffTheReplyIsExactlyWhatItWasBefore(): void
    {
        // Off by default: which substitutions are worth offering is a merchant's decision about
        // their own range, and a plugin update must not start making it for them.
        $product = $this->blueM(suggest: false);

        self::assertSame(true, $product['soldOut'] ?? null);
        self::assertArrayNotHasKey('alternatives', $product);
    }

    public function testTheAlternativesRideOnTheProductTheyAreAboutRatherThanTheReplyRoot(): void
    {
        // A key at the root would have no product to belong to the moment this path returns more
        // than one card, and nothing in the shape would say which one it described.
        $result = $this->tool(suggest: true)(productId: 'fx-026', options: [['option' => 'Blue'], ['option' => 'M']]);

        self::assertArrayNotHasKey('alternatives', $result);
        self::assertArrayHasKey('alternatives', $result['products'][0] ?? []);
    }
}
