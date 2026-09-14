<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\CardPayload;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * The other half of the document contract: the address the model never sees does reach the client.
 *
 * Read this beside {@see \Swag\AssistantStarterKit\Tests\Core\Tool\ToolProductSummaryDocumentsTest},
 * which asserts the opposite for the model's own boundary. Together they are the whole of the first
 * stage: the assistant says a document exists, the shop says where it is.
 *
 * Every figure here still comes from a rendered card and nothing is parsed out of prose — ruling
 * R47 — and a document URL is no exception: it comes off the `ProductCard` the server holds.
 */
#[CoversClass(CardPayload::class)]
final class CardPayloadDocumentsTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(ProductCard $card): array
    {
        $payloads = (new CardPayload())->of([$card]);
        self::assertCount(1, $payloads);

        return $payloads[0] ?? [];
    }

    public function testTheClientGetsTitleUrlAndFormat(): void
    {
        $payload = $this->payloadOf($this->card([
            new ProductDocument(title: 'Datenblatt', url: '/media/ab/cd/x.pdf', extension: 'pdf'),
        ]));

        self::assertSame(
            [['title' => 'Datenblatt', 'url' => '/media/ab/cd/x.pdf', 'extension' => 'pdf']],
            $payload['documents'],
        );
    }

    /**
     * Always present, unlike the model's own key: a client rendering a list needs an empty list
     * rather than a missing field, and nothing reads a shopper-facing meaning into it.
     */
    public function testAProductWithNoDocumentsSendsAnEmptyList(): void
    {
        self::assertSame([], $this->payloadOf($this->card([]))['documents']);
    }

    /**
     * The live rendering that made this necessary: six files, four of them one datasheet in four
     * languages, and a card capped at three rows offering the Dutch one while hiding the English.
     */
    public function testLanguageVariantsCollapseToOneRowPerDocument(): void
    {
        $payload = $this->payloadOf($this->card([
            new ProductDocument('Technical datasheet Hex Bolt M5 German', '/media/a/de.pdf', 'pdf'),
            new ProductDocument('Technical datasheet Hex Bolt M5 English', '/media/a/en.pdf', 'pdf'),
            new ProductDocument('Technical datasheet Hex Bolt M5 Dutch', '/media/a/nl.pdf', 'pdf'),
            new ProductDocument('Safety data sheet EN', '/media/a/sds.pdf', 'pdf'),
        ]));

        $documents = $payload['documents'];
        self::assertIsArray($documents);

        $rows = array_map(static fn(mixed $d): mixed => (
            \is_array($d) ? [$d['title'] ?? null, $d['url'] ?? null] : null
        ), $documents);

        // The merchant's first file wins its document's row; the rest live on the product page.
        self::assertSame(
            [
                ['Technical datasheet Hex Bolt M5', '/media/a/de.pdf'],
                ['Safety data sheet',               '/media/a/sds.pdf'],
            ],
            array_values($rows),
        );
    }

    public function testEveryDocumentIsSentInTheMerchantsOrder(): void
    {
        $payload = $this->payloadOf($this->card([
            new ProductDocument(title: 'Sicherheitsdatenblatt', url: '/media/a/sdb.pdf', extension: 'pdf'),
            new ProductDocument(title: 'Montageanleitung', url: '/media/a/manual.pdf', extension: 'pdf'),
        ]));

        $documents = $payload['documents'];
        self::assertIsArray($documents);

        $titles = array_map(static fn(mixed $document): mixed => \is_array($document)
            ? $document['title'] ?? null
            : null, $documents);
        self::assertSame(['Sicherheitsdatenblatt', 'Montageanleitung'], array_values($titles));
    }
}
