<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\Departments;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * The one fact that lets an ambiguous word be noticed, and the two ways it must stay quiet.
 *
 * It is absent for a gateway that records no path — today every real Shopware shop — so shipping
 * this ahead of the DAL half changes nothing anywhere it is not wanted. And it is the TOP level
 * only: the rest of a category path describes what a product is, which its name already says.
 */
#[CoversClass(Departments::class)]
#[CoversClass(ToolProductSummary::class)]
final class DepartmentsTest extends TestCase
{
    /**
     * @param list<string> $categoryPath
     *
     * @return array<string, mixed>
     */
    private function summaryOf(array $categoryPath): array
    {
        $card = new ProductCard(
            id: 'hj-42303453',
            parentId: null,
            name: 'Innensechskantschraube',
            description: null,
            price: 4.20,
            currency: 'EUR',
            stock: 12,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/hj-42303453',
            imageUrl: null,
            categoryPath: $categoryPath,
        );

        $summaries = ToolProductSummary::of([$card]);
        self::assertCount(1, $summaries);

        return $summaries[0] ?? [];
    }

    public function testTheModelIsToldWhichDepartmentAProductCameFrom(): void
    {
        $summary = $this->summaryOf(['Fahrradteile', 'Komponenten', 'Kleinteile', 'Schrauben & Muttern']);

        self::assertSame('Fahrradteile', $summary['department']);
    }

    /**
     * Everything below the first element describes WHAT the product is, which the name says better —
     * and a model handed a four-level path will eventually recite one at a shopper.
     */
    public function testOnlyTheTopLevelIsSent(): void
    {
        $summary = $this->summaryOf(['Fahrradteile', 'Komponenten', 'Kleinteile']);

        self::assertStringNotContainsString('Komponenten', json_encode($summary, \JSON_THROW_ON_ERROR));
    }

    /**
     * The property that makes this safe to ship before the DAL populates category paths: a shop
     * that records none behaves exactly as it did before.
     */
    public function testAGatewayThatRecordsNoPathSaysNothing(): void
    {
        self::assertArrayNotHasKey('department', $this->summaryOf([]));
    }

    public function testABlankDepartmentIsNotADepartment(): void
    {
        self::assertArrayNotHasKey('department', $this->summaryOf(['   ']));
    }

    /**
     * The failure this exists for: two products of the same NAME, told apart by nothing else.
     */
    public function testTwoProductsOfTheSameNameAreToldApartByTheirDepartment(): void
    {
        $bicycle = $this->summaryOf(['Fahrradteile', 'Komponenten']);
        $motorcycle = $this->summaryOf(['Motorrad- und Rollerteile', 'Befestigungsmaterial']);

        self::assertSame($bicycle['name'], $motorcycle['name']);
        self::assertNotSame($bicycle['department'], $motorcycle['department']);
    }
}
