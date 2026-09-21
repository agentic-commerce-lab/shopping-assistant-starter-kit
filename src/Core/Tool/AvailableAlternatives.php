<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;

/**
 * When the variant a shopper named is sold out, the ones beside it they could buy today.
 *
 * ## Why combinations and not option values
 *
 * {@see FamilyOptionValues} already collects a family's option values per group, and reusing it here
 * would have been three lines. It is the wrong shape for this question. Fixture family `fx-026`:
 * Blue/M is sold out, Blue/L and Black/M are stocked. Per group that reads
 * *"Colour: Blue, Black · Size: L, M"* — four buyable jerseys, of which the shop has two. **Black/L
 * does not exist**, and an assistant offering it would have invented a product while every
 * individual value it said was true.
 *
 * So each entry here is one real variant's own options, and a shopper can be offered nothing the
 * shop cannot ship. This is the same rule the rest of the pipeline keeps — the model states what the
 * shop holds, never what the shop's data implies.
 *
 * ## Only siblings, never "something similar"
 *
 * A sibling variant is a **fact**: the same product, in stock, one option different. *"Something
 * similar"* is a merchant's decision about their own range — which substitutions flatter the brand,
 * which cannibalise, which are simply insulting to offer. The pilot feedback that asked for this said
 * so itself: *"only when that makes business sense"*. Nothing in the catalogue tells us when that is,
 * so this offers the one kind of alternative that needs no judgement, and a standalone sold-out
 * product gets no suggestion at all.
 *
 * ## What it must not carry
 *
 * Options only — no price, no stock figure, no delivery time, no URL, not even a name. The same rule
 * {@see ToolProductSummary} and {@see TruncatedFamilies} keep, and for the same reason: a figure the
 * model did not have to earn is a figure it will quote.
 */
final class AvailableAlternatives
{
    /**
     * How many alternatives are worth offering.
     *
     * Five, not {@see FamilyOptionValues::MAX_VALUES}' fifty. That cap answers *"what does this
     * family contain?"*, where completeness is the point. This answers *"what should I buy
     * instead?"*, and a shopper handed forty options has been handed the problem back. A run of
     * sizes is listed in the catalogue's own order, so the five are the nearest ones the shop chose
     * to present first rather than an arbitrary slice.
     */
    public const MAX_ALTERNATIVES = 5;

    private function __construct() {}

    /**
     * @return array{alternatives?: list<array<string, string>>, alternatives_truncated?: true}
     */
    public static function keyFor(ProductCard $card, ?FamilyVariantLookup $lookup, CatalogScope $scope): array
    {
        $parentId = $card->parentId;

        // Nothing to solve for a product a shopper can buy, and nothing honest to say for one with
        // no family. A gateway without family lookup degrades to saying nothing, exactly as
        // `AssistantAgentFactory::familyOptionsOf()` does.
        if ($card->isInStock() || $parentId === null || $lookup === null) {
            return [];
        }

        $alternatives = [];

        // Through `$scope`, so a blocked sibling is never named.
        foreach ($lookup->variantsOf($parentId, $scope) as $sibling) {
            if ($sibling->id === $card->id || !$sibling->isInStock() || $sibling->options === []) {
                continue;
            }

            $alternatives[] = $sibling->options;
        }

        if ($alternatives === []) {
            return [];
        }

        $offered = \array_slice($alternatives, offset: 0, length: self::MAX_ALTERNATIVES);
        $key = ['alternatives' => $offered];

        // Absent rather than false, like `soldOut` and `available` on the summary beside it: an
        // always-present key is one the model has to reason about on every product.
        if (\count($alternatives) > \count($offered)) {
            $key['alternatives_truncated'] = true;
        }

        return $key;
    }
}
