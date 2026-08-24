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
            . 'name another. You still have no price or stock for it here — use your tools.',
            $summary['name'],
            $described,
            $summary['id'],
        );
    }
}
