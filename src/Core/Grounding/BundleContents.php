<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * A bundle's contents written as the sentence the shop would have written, for the audit to check
 * against.
 *
 * **Why this is support and not a second source of truth.** Once
 * {@see SuppliedFactClaimExtractor} reads a contents list, *every* answer about a bundle is a
 * scope-of-delivery claim — including the correct ones. A shop that told the model exactly what is
 * in the box must not then have the right answer reported as unsupported: that is the failure
 * ruling R85 describes, and with bundles the correct answer is the common case, so it would have
 * been this detector's most frequent finding.
 *
 * **Read off the retrieved cards rather than a trace stage of its own**, because the card is what
 * the model's summary was built from. {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary::of()}
 * emits its `bundle` key for exactly the cards {@see FactRenderer::registerRetrieved()} was given,
 * so "supplied to the model" and "carried by a retrieved card" are the same set — and unlike
 * {@see \Swag\AssistantStarterKit\Core\Tool\GivenDescriptions}, no tool has to remember to record
 * anything for the audit to see it.
 *
 * **`is supplied with` is deliberate phrasing.** The corpus is prose the audit reads as the shop's
 * own assertions, and this is the sentence a merchant would write; `PropertyMention::assertedIn()`
 * then finds the item names in it the same way it finds a material in a description.
 *
 * An optional item is listed like any other. It is genuinely part of the bundle — a shopper may
 * decline it, which is a fact about the price and not about whether the item is in the set — so
 * naming it is not an invention. Which items the quoted figure covers is
 * {@see \Swag\AssistantStarterKit\Core\Prompt\CapabilityRules}' business, not this file's.
 */
final readonly class BundleContents
{
    /**
     * One sentence per retrieved bundle; ordinary products contribute nothing.
     *
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    public static function of(array $cards): array
    {
        $sentences = [];

        foreach ($cards as $card) {
            if ($card->bundleItems === []) {
                continue;
            }

            $sentences[] = \sprintf(
                '%s is supplied with %s.',
                $card->name,
                implode(', ', array_map(self::part(...), $card->bundleItems)),
            );
        }

        return $sentences;
    }

    /**
     * A quantity above one is stated, and one is not — the same rule
     * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} applies to what the model is
     * handed, so the support text and the summary say the same thing.
     */
    private static function part(BundleItem $item): string
    {
        return $item->quantity > 1 ? $item->quantity . ' × ' . $item->name : $item->name;
    }
}
