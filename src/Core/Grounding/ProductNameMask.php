<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Blanks the retrieved products' own names out of a reply before its property claims are extracted.
 *
 * ## The defect this exists for
 *
 * Measured on the staging shop, 2026-09-02. Asked for blue jerseys in size M, the assistant listed
 * what it found — including *"**Trail** Jersey (ausverkauft)"* — and the reply came back carrying
 * `unbackedPropertyClaims: ["Trail"]`, so the shopper was shown a correction note for a sentence that
 * was entirely true.
 *
 * `Trail` is a value in this catalogue's property vocabulary (a Terrain value) AND the first word of a
 * product's name. {@see PropertyClaimExtractor} scans prose against that closed vocabulary, so it read
 * the name as a claim about an attribute; no retrieved product carries `Trail` as a property, so the
 * claim was unbacked. Any shop whose product names borrow from its own facet values has this: Trail
 * Jersey, Gravel Jacket, Thermal Jersey Long Sleeve.
 *
 * A product's name is not a claim about its attributes. It is a label the shop itself chose, and it is
 * already validated on the other axis — {@see ProseProductNames} matches those same names to decide
 * which cards to render.
 *
 * ## Why masking, and why longest first
 *
 * The same technique {@see ProseProductNames} uses, for the same reason: names are tried longest
 * first, and each match is blanked out of the working copy, so `Chain` cannot match inside `Wet Chain
 * Lube 100ml`. Blanked rather than removed, so two neighbouring names cannot join into a third word.
 *
 * What survives masking is still audited. *"Das Trail Jersey ist aus Merino"* loses `Trail Jersey` and
 * keeps `ist aus Merino`, so an invented material is caught exactly as before — only the name itself
 * stops being read as an attribute.
 */
final class ProductNameMask
{
    private function __construct() {}

    /**
     * @param array<string, string> $namesById product id => the product's name
     */
    public static function strip(string $prose, array $namesById): string
    {
        $remaining = $prose;

        foreach ((new ProductNameIndex($namesById))->namesLongestFirst() as $name) {
            $remaining = str_ireplace($name, ' ', $remaining);
        }

        return $remaining;
    }
}
