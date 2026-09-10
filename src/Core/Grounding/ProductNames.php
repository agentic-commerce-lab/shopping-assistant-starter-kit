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

    /**
     * {@see self::of()} plus the name of every bundle member those cards carry.
     *
     * **For masking only, and that is why it is a separate method.** A bundle's members are names
     * the server handed over but which have no card of their own, so they belong in
     * {@see ProductNameMask} — a name must not be read as a property claim — and emphatically not in
     * {@see ContinuedProductNames}, whose whole job is to notice a name no card corroborates.
     *
     * Measured live on 2026-09-10: asked to show the shop's bundles, the assistant listed *"Tubeless
     * Rim Tape, Valve Set …"* and `claims.audit` reported `unbackedPropertyClaims: ["Rim"]`, because
     * `Rim` is one of that catalogue's two `Brake system` values. The reply claimed nothing about
     * brakes; it named a product whose name happens to contain a facet value.
     *
     * Member keys are synthetic (`<bundle id>#<position>`) because a member has no id on the card.
     * Nothing that consumes this may key exclusions off them — {@see ProductNameIndex} drops entries
     * by id, and only the mask call site, which excludes nothing, uses this.
     *
     * @param list<ProductCard> $cards
     *
     * @return array<string, string>
     */
    public static function withBundleItems(array $cards): array
    {
        $names = self::of($cards);

        foreach ($cards as $card) {
            foreach ($card->bundleItems as $position => $item) {
                $names[$card->id . '#' . $position] = $item->name;
            }
        }

        return $names;
    }
}
