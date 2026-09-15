<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;
use Swag\AssistantStarterKit\Core\Tool\DocumentLanguageSuffix;

/**
 * A product's documents as the CARD shows them: one entry per document, not per file.
 *
 * ## Why the card collapses too, having deliberately not collapsed at first
 *
 * {@see \Swag\AssistantStarterKit\Core\Tool\DocumentTitles} collapses language variants for the
 * MODEL, and this payload deliberately did not — the reasoning was that a shopper who wants the
 * Dutch sheet must still be able to reach it, and a card can afford the rows.
 *
 * It cannot. Measured on the demo shop 2026-09-14, rendered live: a product with six files showed
 * *"Declaration of conformity"*, *"Technical datasheet Hex Bolt M5 **Dutch**"* and *"Safety data
 * sheet EN"*, because the card caps its list at three and the raw list is ordered by whatever the
 * merchant attached first. An English shop offering the Dutch variant of a datasheet — and hiding
 * the English one behind "+3 more" — is worse than offering fewer documents.
 *
 * So the card shows one row per document, and the shopper who wants a specific language follows
 * "View product", which is on every card and leads to all of them. That is the trade, stated
 * plainly: the card is an index, the product page is the archive.
 *
 * **The rule lives here in PHP rather than in `card.js`** because
 * {@see DocumentLanguageSuffix} already holds the measured language list, and a second copy in
 * JavaScript would be a second thing to get wrong.
 */
final class CardDocuments
{
    private function __construct() {}

    /**
     * @param list<ProductDocument> $documents
     *
     * @return list<array{title: string, url: string, extension: string}> in the merchant's own
     *         order, the first file of each document winning its row
     */
    public static function of(array $documents): array
    {
        $byTitle = [];

        foreach ($documents as $document) {
            $title = DocumentLanguageSuffix::strip($document->title);

            // First one wins: the merchant's own order decides which file a document's row points
            // at, the same way it decides the order of the rows themselves.
            $byTitle[$title] ??= [
                'title' => $title,
                'url' => $document->url,
                'extension' => $document->extension,
            ];
        }

        return array_values($byTitle);
    }
}
