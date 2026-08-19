<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class AddToCartToolTest extends TestCase
{
    private TraceRecorder $trace;

    // Initialized here (rather than via a default property value, which cannot nest a
    // "new" inside another "new"'s arguments) purely to satisfy the analyzer's
    // uninitialized-property check; tool() below still replaces it before any
    // assertion runs, so this only matters for the analyzer, not test behaviour.
    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    private function tool(AssistantConfig $config = new AssistantConfig()): AddToCartTool
    {
        $this->trace = new TraceRecorder();

        return new AddToCartTool(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
            $this->trace,
            $config,
        );
    }

    public function testAddsTheRequestedVariantAndReportsTheCart(): void
    {
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2);

        self::assertSame(2, $result['cart']['itemCount']);
        self::assertSame('allowed', $this->trace->payload('tool.call')['policyReasonCode']);
    }

    public function testBlocksAQuantityAboveMaxItemQuantity(): void
    {
        $result = $this->tool(new AssistantConfig(maxItemQuantity: 5))(variantId: 'fx-026-blue-l', quantity: 99);

        self::assertSame('cart_limit', $this->trace->payload('tool.call')['policyReasonCode']);
        self::assertArrayNotHasKey('cart', $result);
        self::assertStringContainsString('at most 5', $result['note']);
    }

    public function testBlocksWhenTheCartWouldExceedMaxCartValue(): void
    {
        $result = $this->tool(new AssistantConfig(maxCartValue: 100.0))(variantId: 'fx-026-blue-l', quantity: 5);

        self::assertSame('cart_limit', $this->trace->payload('tool.call')['policyReasonCode']);
        self::assertArrayNotHasKey('cart', $result);
    }

    public function testReportsAnUnknownVariantInsteadOfGuessing(): void
    {
        $result = $this->tool()(variantId: 'fx-999');

        self::assertStringContainsString('No such product', $result['note']);
    }

    public function testRejectsAnOversizedVariantId(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(variantId: str_repeat('x', 200));
    }
}
