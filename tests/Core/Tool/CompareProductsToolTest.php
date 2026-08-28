<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\CompareProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class CompareProductsToolTest extends TestCase
{
    private function tool(): CompareProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();

        return new CompareProductsTool(
            $gateway,
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }

    public function testComparesTwoExistingProducts(): void
    {
        // fx-017 and fx-007 are known-good fixture ids, already relied on by
        // GroundingOutputProcessorTest against this same tests/Fixtures/catalog.json.
        $result = $this->tool()(productIds: ['fx-017', 'fx-007']);

        self::assertCount(2, $result['products']);
    }

    public function testRejectsFewerThanTwoIds(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(productIds: ['fx-017']);
    }

    public function testRejectsMoreThanFourIds(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(productIds: ['fx-017', 'fx-007', 'fx-008', 'fx-014', 'fx-026']);
    }

    public function testNotesWhenSomeIdsDidNotResolve(): void
    {
        // fx-999 is the established "known-nonexistent" id already used the same way by
        // GetProductToolTest::testReportsAnUnknownProductInsteadOfInventingOne().
        $result = $this->tool()(productIds: ['fx-017', 'fx-999']);

        self::assertCount(1, $result['products']);
        self::assertArrayHasKey('note', $result);
    }
}
