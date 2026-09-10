<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * A delivery marker followed by a **list**, which is how a bundle answer is always written.
 *
 * ## The shape the prose reader structurally cannot see
 *
 * {@see SuppliedFactClaimExtractor} walks forward from the marker collecting words separated by
 * `[\s,]+`. A colon is neither, so for
 *
 * ```
 * The Drivetrain Care Bundle includes:
 * - Chain lube 120 ml
 * - Gear brush
 * ```
 *
 * it extracted the bare word `includes`, which {@see ClaimTokens} then correctly discarded as a
 * claim attached to no noun. Nothing was reported — and both lines were inventions: measured live on
 * 2026-09-10, the catalogue held no product whose name contains "brush" and that bundle's lube is
 * 100 ml, not 120.
 *
 * Widening the prose reader's separator class instead would have reached the first item only, and
 * dragged the marker's own sentence into the phrase. A list is a different grammar, so it gets its
 * own reader — the seam this directory already uses between {@see PropertyMention} and
 * {@see PropertyNegation}.
 *
 * ## One claim per item
 *
 * Not one for the list. Reporting only the first item would flag the reply above but pass one whose
 * first item is real and whose second is invented, and the merchant reading `claims.audit` needs the
 * item that was wrong rather than the one that happened to come first.
 *
 * {@see ClaimStands} is applied to the marker's own preamble exactly as the prose reader applies it,
 * so *"the shop does not list what it includes:"* followed by items still asserts nothing. The
 * terminator is empty because a list is not a question — the `?` case cannot arise on this shape.
 */
final readonly class ListedFactClaims
{
    /**
     * @return list<string> one claim per list item, first appearance order, each reported once
     */
    public function in(string $text): array
    {
        $pattern = \sprintf(
            '/(.{0,%3$d}?)\b(?:%1$s)\b[ \t]*:[ \t]*\R((?:[ \t]*%2$s[^\r\n]*(?:\R|$))+)/isu',
            ClaimPhrase::GRAMMAR,
            ListItemClaim::BULLET,
            ClaimStands::CHARS,
        );

        if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $claims = [];

        foreach ($matches as $match) {
            foreach ($this->itemsOf($match) as $claim) {
                $claims[$claim] = true;
            }
        }

        return array_map(strval(...), array_keys($claims));
    }

    /**
     * The claims one matched list contributes, or none when its marker asserts nothing.
     *
     * @param array<array-key, string> $match
     *
     * @return list<string>
     */
    private function itemsOf(array $match): array
    {
        if (!ClaimStands::at($match[1] ?? '', '')) {
            return [];
        }

        $claims = [];

        foreach (preg_split('/\R/u', $match[2] ?? '') ?: [] as $line) {
            $claim = ListItemClaim::of($line);

            if ($claim !== '') {
                $claims[] = $claim;
            }
        }

        return $claims;
    }
}
