<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * Tells the model which product the shopper currently has open.
 *
 * **Built through {@see ToolProductSummary} rather than from the card directly, and that is the
 * point of this class existing at all.** The summary is an allowlist of id, name, option values and
 * bounded `properties`; reading the card here would put a price and a stock level one property
 * access away from the system prompt, and a figure in the prompt is a figure the model can quote
 * without earning it. Option and property values are already in the prompt via the catalogue
 * vocabulary, so this opens no fabrication surface that was not already open. `reasons` is part of
 * the same allowlisted shape (behind `enableMatchReasons`) but never appears here: this class calls
 * {@see ToolProductSummary::of()} with no reason codes, since match reasons are computed only for a
 * `search_products` result, not for the product the shopper happens to be viewing.
 *
 * Since the prompt is recorded as a trace event and shown in the Administration, the model is no
 * longer the only reader of this line — which is a second reason it carries no figures.
 *
 * The line is a statement of fact, not an instruction: what the assistant may *do* is decided by
 * which tools were constructed, never by prompt text (D6).
 *
 * ## The wording is load-bearing, and that is measured
 *
 * This sentence closed with *"You still have no price or stock for it here — use your tools."* on
 * its first live run (2026-08-24, `anthropic/claude-sonnet-5`). Asked *"is this in stock?"* on the
 * Blue/M variant page, the model did exactly as told: it called `get_product` for the product
 * already sitting in its own prompt and already registered on the renderer. Two model round trips,
 * **13.8 s**, for a card the shop was going to render either way.
 *
 * Telling it instead that the card is *already being shown* took the same question to **one** round
 * trip, **zero** tool calls and **3.7 s** — the same architecture, the same code, one sentence. The
 * two questions that genuinely need another variant ("in black?", "in blue, size M?" on the parent)
 * still spend their one tool call, which is correct: the shortcut is for the product on screen, not
 * for the catalogue.
 *
 * So: do not soften "do not call a tool to look this product up" into a hint, and do not reintroduce
 * "use your tools" — that clause is what the measurement caught costing ten seconds.
 */
final class ViewingContext
{
    private function __construct() {}

    /**
     * @param array<string, list<string>> $familyOptions every option value the viewed product's
     *                                                   family offers, from `FamilyOptionValues`;
     *                                                   empty for a standalone product
     */
    public static function line(?ProductCard $card, array $familyOptions = []): string
    {
        if ($card === null) {
            return '';
        }

        $summary = ToolProductSummary::of([$card])[0] ?? null;

        if ($summary === null) {
            return '';
        }

        $options = [];
        foreach ($summary['options'] as $group => $value) {
            $options[] = $group . ': ' . $value;
        }

        $described = $options === [] ? '' : ' (' . implode(', ', $options) . ')';

        return \sprintf(
            'The shopper is currently looking at this product: %s%s [id %s]. '
            . 'When they say "this", "it" or "that", they mean this product unless they clearly '
            . 'name another. Its card is ALREADY being shown to them alongside your answer, with '
            . 'its real price and availability filled in by the shop — so do not call a tool to '
            . 'look this product up. Call one only if you need a DIFFERENT product or a different '
            . 'variant. Never state a figure yourself: the card carries them.%s',
            $summary['name'],
            $described,
            $summary['id'],
            self::familyClause($familyOptions),
        );
    }

    /**
     * What the rest of the family offers, or `''` when there is no choice to describe.
     *
     * **Groups with a single value are dropped**, and that is the whole subtlety. A one-variant
     * family is not a choice, and printing *"also available in Size: M"* directly under *"you are
     * looking at Size: M"* states one fact twice in two different voices — which invites the model
     * to read the repetition as significant. Dropping them also means a standalone product's line is
     * byte-for-byte the one it was before this argument existed, so nothing changes on the CMS and
     * listing pages that make up most of a shop.
     *
     * Values only, never a count and never a figure: the same allowlist reasoning as the viewed
     * card's own options, and these values are already in the prompt through the catalogue
     * vocabulary.
     *
     * @param array<string, list<string>> $familyOptions
     */
    private static function familyClause(array $familyOptions): string
    {
        $choices = [];

        foreach ($familyOptions as $group => $values) {
            if (\count($values) < 2) {
                continue;
            }

            $choices[] = $group . ': ' . implode(', ', $values);
        }

        if ($choices === []) {
            return '';
        }

        // Stated as fact, then the one thing the model must still do — mirroring the sentence above
        // it, which was measured into its current wording. The model may answer "which sizes do you
        // have?" straight from this; it may NOT claim a particular one is in stock, because a
        // variant's availability is a figure and figures live on cards.
        return \sprintf(' The same product also comes in — %s. You may answer questions about which options '
        . 'exist directly from this list. To show one, or to say anything about its price or '
        . 'availability, call a tool for that variant.', implode('; ', $choices));
    }
}
