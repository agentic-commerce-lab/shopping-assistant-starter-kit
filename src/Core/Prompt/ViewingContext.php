<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * Tells the model which product the shopper currently has open.
 *
 * **Built through {@see ToolProductSummary} rather than from the card directly, and that is the
 * point of this class existing at all.** The summary is an allowlist of id, name and option values;
 * reading the card here would put a price and a stock level one property access away from the
 * system prompt, and a figure in the prompt is a figure the model can quote without earning it.
 * Option values are already in the prompt via the catalogue vocabulary, so this opens no
 * fabrication surface that was not already open.
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

    public static function line(?ProductCard $card): string
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
            . 'variant. Never state a figure yourself: the card carries them.',
            $summary['name'],
            $described,
            $summary['id'],
        );
    }
}
