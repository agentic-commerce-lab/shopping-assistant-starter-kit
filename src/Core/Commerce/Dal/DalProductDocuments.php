<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;

/**
 * Reads a product's attached documents off its media association.
 *
 * ## Why the media gallery, and why an extension allowlist is the whole filter
 *
 * Shopware has no dedicated "product documents" association. A merchant attaching a datasheet has
 * three realistic places to put it, and the gallery — `product_media` — is the one that needs no
 * configuration and no second query: the rows are already a product association, and the media
 * entity behind each row carries its own file extension.
 *
 * That makes the allowlist load-bearing rather than defensive. **Without it every gallery image
 * becomes a "document"**, and a shopper asking about a helmet is told there are nine documents for
 * it. The list is deliberately short — see {@see self::EXTENSIONS} — and a format nobody attaches as
 * a datasheet is not on it.
 *
 * **`product_download` is deliberately not read.** That association is Shopware's digital-product
 * mechanism: the file is what the customer buys, served behind a purchase check. Surfacing it here
 * would hand out paid content to anyone who asks, and no allowlist can tell the two intents apart —
 * a merchant who sells PDFs and a merchant who documents hardware attach the same file type. A shop
 * that wants those surfaced needs an access rule, which is its own piece of work.
 *
 * ## Typed core entities, unlike {@see DalBundleItems}
 *
 * That class cannot name the types it reads, because Shopware Commercial is not a dependency here.
 * `product_media` is core, so this one reads it the way {@see DalProductCardMapper} already reads
 * the cover image — `getCover()?->getMedia()?->getUrl()` — with real getters and no `has()`/`get()`
 * dance. Every property it touches is declared with a default on {@see MediaEntity}
 * (`url` is `string = ''`, the rest `?string = null`), so a partially hydrated media row degrades to
 * a skipped document rather than to the uninitialised-typed-property `Error` that rulings R48 and
 * R49 exist for.
 */
final readonly class DalProductDocuments
{
    /**
     * The association {@see DalCriteriaBuilder} loads and this class reads back.
     *
     * One constant for both uses, so the two cannot drift apart — the same reason
     * {@see DalBundleItems::ASSOCIATION} exists.
     */
    public const ASSOCIATION = 'media';

    /**
     * File extensions a merchant attaches as product documentation.
     *
     * **PDF only, and that is a decision rather than a starting point.** It is what datasheets,
     * manuals and safety data sheets are actually distributed as, and every other candidate is
     * either an image — already in this association, and the reason a filter is needed at all — or
     * an office format a shop would not link a shopper to unopened. Widening this list widens what
     * the shopper is told exists, so it is a decision to take on evidence, not by default.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['pdf'];

    /**
     * @return list<ProductDocument> in the merchant's own gallery order, as the association was sorted
     */
    public function of(SalesChannelProductEntity $product): array
    {
        $documents = [];

        foreach ($product->getMedia() ?? [] as $row) {
            $document = self::document($row);

            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * One `product_media` row as a document, or null when it is not one.
     *
     * Null covers three different cases on purpose — the media was not loaded, the file is not on
     * the allowlist, or it has no URL — because all three mean the same thing to the shopper: there
     * is nothing here to link to.
     */
    private static function document(ProductMediaEntity $row): ?ProductDocument
    {
        $media = $row->getMedia();

        if (!$media instanceof MediaEntity) {
            return null;
        }

        $extension = strtolower($media->getFileExtension() ?? '');
        $url = $media->getUrl();

        if (!\in_array($extension, self::EXTENSIONS, true) || $url === '') {
            return null;
        }

        return new ProductDocument(title: self::title($media, $extension), url: $url, extension: $extension);
    }

    /**
     * What the shopper sees as the link's label.
     *
     * **The media title first, the file name only as a fallback.** Real datasheet file names are
     * catalogue identifiers rather than labels — `41101981_SDB_LM_Kaeltespray_8916_DE` is an actual
     * one, from the shop this was built for — and putting that in front of a shopper is worse than a
     * generic word. The last resort is the format itself, which at least reads as a label.
     *
     * The title is read through `translated` first, for the reason {@see DalProductCardMapper}
     * documents for a product's own name: that is where the DAL puts a field after resolving the
     * language chain, and the own-value getter can be null while a translation exists.
     */
    private static function title(MediaEntity $media, string $extension): string
    {
        $translated = $media->getTranslation('title');

        foreach ([$translated, $media->getTitle(), $media->getFileName()] as $candidate) {
            if (\is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return strtoupper($extension);
    }
}
