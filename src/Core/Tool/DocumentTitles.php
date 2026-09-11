<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductDocument;

/**
 * The names of a product's documents, as the model is allowed to hear them.
 *
 * Split from {@see ToolProductSummary} for the reason {@see BoundedProperties} was: that class sums
 * its methods against this project's complexity gate, and a per-field shaping rule is exactly the
 * kind of thing that belongs beside the field rather than inside the summary builder.
 *
 * **Titles only — the URL never appears here, and that is the point.** The system prompt forbids the
 * model to write a link, so handing it one would be handing it the one string it may not use. The
 * shop renders the address from the same card, at the HTTP boundary.
 */
final class DocumentTitles
{
    private function __construct() {}

    /**
     * Deduplicated, in the merchant's own order.
     *
     * A merchant who attached the same datasheet twice has told the shopper nothing twice, and a
     * model reading "Datenblatt, Datenblatt" describes two documents. The card still renders both
     * rows: that is the shop's own record of what is attached, and this is only what the model is
     * told about it.
     *
     * @param list<ProductDocument> $documents
     *
     * @return list<string>
     */
    public static function of(array $documents): array
    {
        return array_values(array_unique(array_map(
            static fn(ProductDocument $document): string => $document->title,
            $documents,
        )));
    }

    /**
     * The summary fragment to merge in: the key, or nothing at all.
     *
     * **Absent rather than empty**, like `soldOut` and `available` beside it — a key that is always
     * there invites the model to say something about it even when there is nothing to say. Returning
     * the fragment rather than a boolean keeps that decision here: {@see ToolProductSummary} sums its
     * branches against this project's complexity gate, and this is the field's own rule.
     *
     * @param list<ProductDocument> $documents
     *
     * @return array{documents?: list<string>}
     */
    public static function keyFor(array $documents): array
    {
        return $documents === [] ? [] : ['documents' => self::of($documents)];
    }
}
