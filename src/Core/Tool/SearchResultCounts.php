<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The counted fields a product search hands the model, and what each of them means.
 *
 * Split out of {@see SearchProductsTool} when `withheld` was added on 2026-09-09 and that file was
 * sitting exactly on the project's ~400-line budget. The seam is worth keeping on its own terms:
 * these four numbers are a contract the model has learned, three separate rulings constrain them,
 * and their meanings were previously legible only as comments inside an array literal in the middle
 * of a 400-line method's caller.
 *
 * ## Why none of them is renamed
 *
 * - **`total` means "how many are in `products`"** (T3), not "how many exist". It reads like the
 *   opposite of what it is, and it stays: the model has learned it, and redefining a field in place
 *   is how something else quietly breaks.
 * - **`matched` is what the search actually found**, which is the number `total` was being read as.
 *   It excludes superseded parents — `RedundantParentFilter` removed them because their own variants
 *   are present, and counting one back in would report a product twice.
 * - **`more` says `matched` is a floor rather than a census** (T4), which is what it is whenever the
 *   candidate window filled up. An exact count from {@see ExactMatchCount} makes it false rather
 *   than "there are more than I showed" — that second fact is `matched` against `total`.
 * - **`withheld` is `matched` minus `total`**, present only when there is a difference. See
 *   {@see WithheldCount} for the measured turns that made it necessary.
 *
 * ## Why the exact count arrives as an argument
 *
 * The caller reads it, because reading it needs the gateway, the candidates and the scope — three
 * collaborators this class would otherwise have to hold to answer one question. Passing the answer
 * in keeps this class a shape rather than a second retrieval step, and keeps the decision about
 * which `matched` is authoritative in one place: `withheld` is computed from the same number the
 * reply reports, so the two can never disagree.
 */
final class SearchResultCounts
{
    private function __construct() {}

    /**
     * @param list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, soldOut?: true, available?: true, reasons?: list<string>}> $products already summarised by {@see ToolProductSummary}
     * @param list<ProductCard> $returned  the cards the shopper will see
     * @param list<ProductCard> $survivors what retrieval found, after the redundant-parent filter
     * @param bool              $saturated whether the candidate window filled up
     * @param int|null          $exact     an authoritative match count, when the gateway could give one
     *
     * @return array{products: list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, soldOut?: true, available?: true, reasons?: list<string>}>, total: int, matched: int, more: bool, withheld?: int}
     */
    public static function of(
        array $products,
        array $returned,
        array $survivors,
        bool $saturated,
        ?int $exact = null,
    ): array {
        return [
            // id + name + options, never a figure — see ToolProductSummary for why bare ids made
            // variant identification cost one tool call per candidate.
            'products' => $products,
            'total' => \count($returned),
            'matched' => $exact ?? \count($survivors),
            'more' => $exact === null && $saturated,
            // **The cards, not the counts.** `withheld` is measured in products rather than rows,
            // and only the card lists carry which rows belong to the same product. See
            // {@see WithheldCount} for the session that makes the difference load-bearing.
            ...WithheldCount::replyFor($survivors, $returned),
        ];
    }
}
