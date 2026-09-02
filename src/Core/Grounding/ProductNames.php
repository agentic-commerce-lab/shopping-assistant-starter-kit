<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The name of every retrieved card, keyed by its id.
 *
 * One line of work in its own class, and the reason is the gate rather than the design:
 * {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor} needs this map twice — once to
 * mask names out of the property audit, once to resolve the names a reply uses to card ids — and
 * building it inline there put that class over its cyclomatic-complexity budget. {@see FactRenderer}
 * hands out cards rather than this map because deciding WHICH variant a reply means needs their
 * option values, and it sits at this project's per-file line ceiling.
 */
final class ProductNames
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return array<string, string> product id => product name
     */
    public static function of(array $cards): array
    {
        $names = [];

        foreach ($cards as $card) {
            $names[$card->id] = $card->name;
        }

        return $names;
    }
}
