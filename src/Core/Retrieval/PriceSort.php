<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

/**
 * The one ordering a shopper can ask for, as a closed vocabulary.
 *
 * ## The defect this exists for
 *
 * Reported from the staging shop, 2026-09-02. Asked *"What is the cheapest jersey"*, the assistant
 * answered:
 *
 * > *"The shop shows the current figures for the products you named."*
 *
 * Which answers nothing, names nothing, and refers to products the shopper never named. It was the
 * only reply available to it: {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} carries no
 * figure by design — that is what makes inventing a price structurally impossible — so the model had
 * no way to tell which of the jerseys was cheapest, and nothing to sort by either.
 *
 * ## Why an ordering rather than the prices
 *
 * Handing the model the prices would answer the question and reopen the hole the whole grounding
 * pipeline exists to close. An ordering answers it without that: the SHOP sorts, and the model reports
 * the position. "The cheapest is the Trail Jersey" names a product and states no figure, and the card
 * beside it carries the number from the server's own record — so the shopper can check the claim
 * against the evidence, which is the arrangement every other figure in this project already has.
 *
 * ## Why a closed enum and not a sort string
 *
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder} passes the query's sort
 * straight into a `FieldSorting`, so a raw string from a model's tool call would be a field name of
 * its choosing reaching the DAL. Nothing was exploiting that — nothing ever set `sort` at all — but
 * the fix arrives with the first caller, not after one.
 *
 * `cheapestPrice` rather than `price`: it is the accessor Shopware's own listing sorts by, and it is
 * the figure a card shows for a family — the cheapest variant's. Sorting by `price` would order
 * families by whichever variant the parent row happens to carry.
 */
enum PriceSort: string
{
    case Ascending = 'price_asc';

    case Descending = 'price_desc';

    /**
     * The value a model may pass, resolved, or null for anything else.
     *
     * Null rather than an exception: an unknown ordering is a request this shop cannot serve, and the
     * honest answer is the shop's own relevance ranking rather than a refused turn.
     */
    public static function fromRequest(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function field(): string
    {
        return 'cheapestPrice';
    }

    /** `ASC`/`DESC` as Shopware's `FieldSorting` spells them. */
    public function direction(): string
    {
        return $this === self::Ascending ? 'ASC' : 'DESC';
    }
}
