<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * How many cards one name is worth, once family is taken into account.
 *
 * ## Why a name may legitimately point at more than one card
 *
 * {@see ProductNameIndex::idFor()} resolves a name to a single id, and that is right for the case it
 * was written for: Shopware names a variant after its parent, so Blue/M and Blue/L are both "Trail
 * Jersey". Rendering both would put a price and a stock figure for a variant the turn never touched
 * in front of the shopper — the live defect of 2026-09-02 that `$preferredIds` exists to fix.
 *
 * **Two different PRODUCTS can also share a name, and then one card is wrong.** Measured live on the
 * demo shop 2026-09-14: two standalone products both called "Hex Bolt M5", one filed under Brakes
 * and one under Components. The reply said *"available from two different departments"* and named
 * both; exactly one card rendered — the Components one, at its own price, carrying none of the six
 * documents that hang on the other. The shopper read an answer about two products and saw a single
 * card contradicting half of it.
 *
 * The family key separates the two cases and nothing else does: variants of a family share a
 * `parentId`, two standalone namesakes do not. It is the same key
 * {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier} groups by, built by
 * {@see ProductNames::familiesOf()}.
 *
 * Split from {@see ProductNameIndex} at this project's complexity gate, and the seam holds up: that
 * class knows how to index names, this one knows the rule that decides how many cards a name buys.
 */
final readonly class NamesakeCards
{
    private function __construct() {}

    /**
     * One id per family that answers to this name, in registration order.
     *
     * **An empty family map means one card, exactly as before.** A caller that cannot say which
     * products are family to each other cannot tell a namesake from a variant either, and the
     * conservative reading is the one that was measured: one card per name.
     *
     * @param array<string, string> $namesById    product id => name, exclusions already applied
     * @param list<string>          $preferredIds applied within each family, as {@see ProductNameIndex::idFor()}
     * @param array<string, string> $familyById   product id => its family key
     *
     * @return list<string>
     */
    public static function of(string $name, array $namesById, array $preferredIds, array $familyById): array
    {
        $byFamily = [];

        foreach ($namesById as $id => $candidate) {
            if ($candidate !== $name) {
                continue;
            }

            // With no family information every id stands alone, and collapsing them under one key
            // reproduces the old one-card behaviour rather than inventing a new one.
            $family = $familyById === [] ? $name : $familyById[$id] ?? $id;

            // Registration order decides, unless a preferred id answers for this family — the same
            // tie-break idFor() makes, applied per family rather than once overall.
            if (!isset($byFamily[$family]) || \in_array($id, $preferredIds, strict: true)) {
                $byFamily[$family] = $id;
            }
        }

        return array_values($byFamily);
    }
}
