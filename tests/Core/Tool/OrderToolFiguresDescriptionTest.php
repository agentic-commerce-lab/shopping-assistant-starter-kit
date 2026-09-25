<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\GetOrderTool;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What the two order tools tell the model it may do with the figures they now return.
 *
 * D3 was relaxed for the shopper's own orders on 2026-09-24, and a description is the only place the
 * model learns where the relaxation stops. Asserted by substring for {@see ListOrdersDescriptionTest}'s
 * reason: a description edit that silently missed is how the last one of these was found, three paid
 * eval runs late.
 *
 * Three things must hold in both tools. The permission is to state a figure **as returned** — not to
 * round it, sum it or fill a gap. The old prohibition is gone, because a description that hands the
 * model a total and forbids stating it is the contradiction testers ran into. And the link rule is
 * untouched: D3 yields on figures, never on URLs.
 */
final class OrderToolFiguresDescriptionTest extends TestCase
{
    /** @param class-string $tool */
    #[DataProvider('orderTools')]
    public function testTheModelMayStateAReturnedFigureExactly(string $tool): void
    {
        $description = self::description($tool);

        self::assertStringContainsString('exactly as returned', $description);
        self::assertStringContainsString('never round', $description);
    }

    /** @param class-string $tool */
    #[DataProvider('orderTools')]
    public function testTheOldProhibitionIsGone(string $tool): void
    {
        $description = self::description($tool);

        self::assertStringNotContainsString('never state a total', $description);
        self::assertStringNotContainsString('never state a quantity', $description);
    }

    /** @param class-string $tool */
    #[DataProvider('orderTools')]
    public function testTheLinkRuleSurvives(string $tool): void
    {
        self::assertStringContainsString('link', self::description($tool));
    }

    /** The reorder complaint: one of each, because no quantity had ever been handed over. */
    public function testGetOrderSaysTheQuantitiesAreWhatToReorder(): void
    {
        self::assertStringContainsString('same again', self::description(GetOrderTool::class));
    }

    /** @return array<string, array{class-string}> */
    public static function orderTools(): array
    {
        return [
            'list_orders' => [ListOrdersTool::class],
            'get_order' => [GetOrderTool::class],
        ];
    }

    /** @param class-string $tool */
    private static function description(string $tool): string
    {
        $attributes = (new \ReflectionClass($tool))->getAttributes(AsTool::class);
        self::assertCount(1, $attributes);

        $attribute = $attributes[0] ?? null;
        self::assertNotNull($attribute);

        return mb_strtolower($attribute->newInstance()->description);
    }
}
