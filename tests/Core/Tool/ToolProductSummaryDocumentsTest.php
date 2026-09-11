<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * What the model is told about a product's documents, and the one thing it must never be told.
 *
 * **The URL is the assertion that matters.** The system prompt forbids the model to state a URL at
 * all, because the shop renders every link the way it renders every figure. Handing it one here
 * would be handing it the exact string it is not allowed to use — and a pasted link that no card
 * backs is indistinguishable, to the shopper, from one the shop stands behind.
 *
 * The complement to this is {@see \Swag\AssistantStarterKit\Tests\Controller\CardPayloadDocumentsTest}:
 * the address does cross a boundary, just the HTTP one.
 */
#[CoversClass(ToolProductSummary::class)]
final class ToolProductSummaryDocumentsTest extends TestCase
{
    /**
     * @param list<ProductDocument> $documents
     */
    private function card(array $documents): ProductCard
    {
        return new ProductCard(
            id: 'fx-017',
            parentId: null,
            name: 'YUASA Batterie YTZ10S',
            description: null,
            price: 89.90,
            currency: 'EUR',
            stock: 7,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/fx-017',
            imageUrl: null,
            documents: $documents,
        );
    }

    private function document(string $title, string $url = '/media/ab/cd/x.pdf'): ProductDocument
    {
        return new ProductDocument(title: $title, url: $url, extension: 'pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOf(ProductCard $card): array
    {
        $summaries = ToolProductSummary::of([$card]);
        self::assertCount(1, $summaries);

        return $summaries[0] ?? [];
    }

    public function testTheModelIsToldThatADocumentExistsAndWhatItIsCalled(): void
    {
        $summary = $this->summaryOf($this->card([$this->document('Technisches Datenblatt')]));

        self::assertSame(['Technisches Datenblatt'], $summary['documents']);
    }

    /**
     * The load-bearing one. A tool result that carried the address would put the one string the
     * prompt forbids directly into the model's context.
     */
    public function testTheUrlNeverReachesTheModel(): void
    {
        $summary = $this->summaryOf($this->card([
            $this->document('Datenblatt', '/media/ab/cd/41101981_SDB_LM_Kaeltespray_8916_DE.pdf'),
        ]));

        self::assertStringNotContainsString(
            '/media/',
            json_encode($summary, \JSON_THROW_ON_ERROR),
            'the tool result handed the model a document URL',
        );
    }

    /**
     * Absent rather than empty, like `soldOut` and `available`: a key that is always there invites
     * the model to say something about it even when there is nothing to say.
     */
    public function testAProductWithNoDocumentsCarriesNoKeyAtAll(): void
    {
        self::assertArrayNotHasKey('documents', $this->summaryOf($this->card([])));
    }

    /**
     * A merchant who attached the same datasheet twice has told the shopper nothing twice.
     */
    public function testARepeatedTitleIsReportedOnce(): void
    {
        $summary = $this->summaryOf($this->card([
            $this->document('Datenblatt', '/media/ab/cd/one.pdf'),
            $this->document('Datenblatt', '/media/ab/cd/two.pdf'),
        ]));

        self::assertSame(['Datenblatt'], $summary['documents']);
    }

    public function testSeveralDistinctDocumentsAreAllNamed(): void
    {
        $summary = $this->summaryOf($this->card([
            $this->document('Sicherheitsdatenblatt', '/media/ab/cd/sdb.pdf'),
            $this->document('Montageanleitung', '/media/ab/cd/manual.pdf'),
        ]));

        self::assertSame(['Sicherheitsdatenblatt', 'Montageanleitung'], $summary['documents']);
    }

    /**
     * The standing rule this class keeps: no figure is ever added here. A document is a name.
     */
    public function testNoFigureRidesAlongWithADocument(): void
    {
        $summary = $this->summaryOf($this->card([$this->document('Datenblatt')]));
        $encoded = json_encode($summary, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('89.9', $encoded);
        self::assertStringNotContainsString('"stock"', $encoded);
    }
}
