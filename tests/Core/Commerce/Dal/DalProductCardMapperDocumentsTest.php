<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductDocuments;
use Swag\AssistantStarterKit\Core\Commerce\Dal\ProductUrlResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * A product's attached datasheets, read off the gallery association it shares with its images.
 *
 * **The allowlist is the entire feature.** `product_media` is where a merchant's photographs live,
 * so a reader without an extension filter reports nine "documents" for a helmet with nine pictures.
 * The cases below exist because that filter has to hold from both sides: images must not become
 * documents, and a real PDF must not be dropped by a filter that is too eager.
 *
 * Nothing here touches a document's CONTENTS — there are none to touch. The first stage surfaces a
 * link and says a document exists; reading the file is separate work, and
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument} says why.
 *
 * Both behaviours are driven from providers rather than a method each, because this project caps a
 * class's method count and these are two rules with many examples, not many rules.
 */
#[CoversClass(DalProductDocuments::class)]
final class DalProductCardMapperDocumentsTest extends TestCase
{
    private const PRODUCT_ID = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    /**
     * One `product_media` row as the DAL hydrates it.
     *
     * `url` is what Shopware's media URL generator has already resolved onto the entity — the same
     * field the mapper reads for the cover image beside this one. A null extension stands for a row
     * whose media the association did not load at all.
     */
    private static function row(
        ?string $extension,
        string $url = '/media/a/x.pdf',
        ?string $title = null,
        ?string $fileName = null,
    ): ProductMediaEntity {
        $row = new ProductMediaEntity();
        // EntityCollection keys elements by identifier; a row without an id makes the collection
        // itself warn, which has nothing to do with what is under test.
        $row->setId(str_pad(substr(md5($url . $extension . $title . $fileName), 0, 32), 32, '0'));

        if ($extension === null) {
            return $row;
        }

        $media = new MediaEntity();
        $media->setId($row->getId());
        $media->setFileExtension($extension);
        $media->setUrl($url);

        // Set only when given: both setters are typed non-nullable, while the properties behind them
        // default to null — which is exactly the "not filled in" state these cases are about.
        if ($title !== null) {
            $media->setTitle($title);
        }

        if ($fileName !== null) {
            $media->setFileName($fileName);
        }

        $row->setMedia($media);

        return $row;
    }

    /**
     * @return list<ProductDocument>
     */
    private function documentsOf(ProductMediaEntity ...$rows): array
    {
        $product = new SalesChannelProductEntity();
        $product->setId(self::PRODUCT_ID);
        $product->setName('YUASA Batterie YTZ10S');
        $product->setStock(7);
        $product->setCalculatedPrice(
            new CalculatedPrice(89.90, 89.90, new CalculatedTaxCollection(), new TaxRuleCollection()),
        );

        if ($rows !== []) {
            $product->setMedia(new ProductMediaCollection($rows));
        }

        $mapper = new DalProductCardMapper(new class implements ProductUrlResolver {
            public function urlFor(string $productId): string
            {
                return '/detail/' . $productId;
            }
        });

        $card = $mapper->map($product, StockSource::Product, 'EUR');
        self::assertNotNull($card);

        return $card->documents;
    }

    /**
     * @param list<ProductMediaEntity> $rows
     * @param list<string>             $expected the URLs that survive the allowlist, in order
     */
    #[DataProvider('galleries')]
    public function testOnlyDocumentsSurviveTheGallery(array $rows, array $expected): void
    {
        $documents = $this->documentsOf(...$rows);

        self::assertSame($expected, array_map(static fn(ProductDocument $d): string => $d->url, $documents));
    }

    /** @return iterable<string, array{list<ProductMediaEntity>, list<string>}> */
    public static function galleries(): iterable
    {
        yield 'a pdf is a document' => [[self::row('pdf', '/media/a/datasheet.pdf')], ['/media/a/datasheet.pdf']];

        // The failure this class exists to prevent: a shopper told a helmet has nine documents.
        yield 'images are not documents' => [
            [
                self::row('jpg', '/media/a/front.jpg'),
                self::row('png', '/media/a/side.png'),
                self::row('webp', '/media/a/detail.webp'),
            ],
            [],
        ];

        yield 'the pdf among the photographs' => [
            [
                self::row('jpg', '/media/a/front.jpg'),
                self::row('pdf', '/media/a/sdb.pdf'),
                self::row('png', '/media/a/side.png'),
            ],
            ['/media/a/sdb.pdf'],
        ];

        // Shopware stores the extension as the merchant's file had it; two of the real datasheets
        // this was built against are `.PDF`.
        yield 'an uppercase extension is still a pdf' => [
            [self::row('PDF', '/media/a/40258592_SDB_Corexx_DE.PDF')],
            ['/media/a/40258592_SDB_Corexx_DE.PDF'],
        ];

        // Rulings R48/R49: a half-loaded association degrades to a shorter list, never to a fatal.
        yield 'a row whose media was not loaded' => [
            [self::row(null), self::row('pdf', '/media/a/ok.pdf')],
            ['/media/a/ok.pdf'],
        ];

        yield 'a pdf with no resolved url is nothing to link to' => [[self::row('pdf', '')], []];

        yield 'no media at all' => [[], []];
    }

    /**
     * The label falls through title → file name → format, and the fallbacks matter: real datasheet
     * file names are catalogue identifiers rather than labels, and a blank title would render an
     * empty link. `41101981_SDB_LM_Kaeltespray_8916_DE` is an actual one from the target shop.
     */
    #[DataProvider('labels')]
    public function testTheLinkLabelFallsThroughToWhatIsActuallyThere(
        ?string $title,
        ?string $fileName,
        string $expected,
    ): void {
        $documents = $this->documentsOf(self::row('pdf', '/media/a/x.pdf', $title, $fileName));

        self::assertSame([$expected], array_map(static fn(ProductDocument $d): string => $d->title, $documents));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function labels(): iterable
    {
        yield 'the media title wins' => ['Technisches Datenblatt', 'raw_name', 'Technisches Datenblatt'];
        yield 'the file name when there is no title' => [
            null,
            '41101981_SDB_LM_Kaeltespray_8916_DE',
            '41101981_SDB_LM_Kaeltespray_8916_DE',
        ];
        yield 'a blank title is not a label' => ['   ', 'manual', 'manual'];
        yield 'the format itself as the last resort' => [null, null, 'PDF'];
    }
}
