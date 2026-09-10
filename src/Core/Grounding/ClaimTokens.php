<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The words in a claim phrase that carry its content — what has to be found in the shop's own prose
 * for {@see DescriptionAudit} to call the claim supported.
 *
 * Split from {@see SuppliedFactClaimExtractor} on the seam that directory already uses twice
 * ({@see PropertyMention} from {@see PropertyNegation}, {@see AvailabilityClaimExtractor} from
 * {@see AvailabilityNegation}): finding a claim and deciding which of its words matter are two jobs
 * revised for different reasons. This file changes when a phrasing turns out to carry noise; that one
 * when a model finds a new way to word a delivery claim.
 *
 * **The marker itself is noise.** `supplied`, `included` and `rated` are the grammar that made the
 * phrase a claim, and every one of them appears in the catalogue's own descriptions — 63 of the 85
 * seeded products say `supplied`. Leaving them in would let *"supplied with a frame bracket"* count
 * as supported because some other product is supplied with something.
 *
 * **A hyphenated compound contributes its parts as well as itself.** `high-security` has to be able
 * to match a description saying `security`, or the check would miss the case it was built for: the
 * locks say *"no security rating is published for it"*, and a reply claiming `rated for
 * high-security use` is contradicted by that sentence rather than merely unsupported by it.
 *
 * **A crude singular is offered alongside the plural**, because the comparison is token-exact:
 * {@see PropertyMention::assertedIn()} anchors on word boundaries, so `helmets` would not find
 * `helmet` and *"it did return trail-rated helmets"* would be reported against a description that
 * does discuss the helmet. Crude on purpose — a stemmer is a dependency and a wrong stem is a silent
 * exemption; dropping one trailing `s` is wrong in a way a reader can predict.
 */
final readonly class ClaimTokens
{
    /**
     * Words that carry no product content, plus every marker {@see SuppliedFactClaimExtractor} keys
     * on.
     *
     * Anything shorter than {@see self::MIN_LENGTH} is dropped by length and is not listed here.
     */
    private const NOISE = [
        'with',
        'that',
        'this',
        'these',
        'those',
        'your',
        'yours',
        'their',
        'from',
        'into',
        'have',
        'been',
        'also',
        'both',
        'than',
        'more',
        'most',
        'much',
        'many',
        'some',
        'when',
        'will',
        'would',
        'need',
        'needs',
        'needed',
        'extra',
        'other',
        'another',
        'which',
        'while',
        'there',
        'here',
        'them',
        'they',
        'it',
        'its',
        'used',
        'uses',
        'using',
        'usual',
        'usually',
        'where',
        'whose',
        'whom',
        'been',
        'being',
        'wenn',
        'aber',
        'oder',
        'sind',
        'nicht',
        'typically',
        'often',
        'about',
        'around',
        'over',
        'under',
        'each',
        'every',
        'only',
        'just',
        // The markers. Present in the shop's own prose constantly, so they must never be what makes
        // a claim look supported — see the class docblock.
        // The generic noun for a bundle's members, and grammar rather than content for the same
        // reason the markers below are: a reply saying a bundle "includes all items" names no
        // product, so the claim could never be supported whatever the shop's prose said. Measured
        // live 2026-09-10, when that exact sentence — the correct answer about what a bundle price
        // covers — was reported as an invented delivery claim (ruling R85).
        'item',
        'items',
        'even',
        'includ',
        'include',
        'includes',
        'included',
        'including',
        'supplied',
        'comes',
        'complete',
        'ships',
        'bundled',
        'delivered',
        'rated',
        'certified',
        'approved',
        'compliant',
        'geliefert',
        'lieferumfang',
        'enthält',
        'zertifiziert',
        'nach',
        'für',
    ];

    /**
     * Below this a token is grammar, not content.
     *
     * Four rather than three keeps `the`, `and`, `for`, `use` and `box` out while keeping `1078`,
     * the one number in the catalogue that is a claim in its own right.
     */
    private const MIN_LENGTH = 4;

    private function __construct() {}

    /**
     * @return list<string> lowercased, deduplicated, in first appearance order
     */
    public static function of(string $phrase): array
    {
        $pieces = preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower($phrase)) ?: [];

        $tokens = [];

        foreach ($pieces as $piece) {
            foreach (self::variantsOf($piece) as $token) {
                $tokens[$token] = true;
            }
        }

        // `array_map` over the keys, because a token that is all digits — `1078`, the one number in
        // this catalogue that is a claim in its own right — comes back from `array_keys()` as an int,
        // and `PropertyMention::assertedIn()` takes a string.
        return array_map(strval(...), array_keys($tokens));
    }

    /**
     * One piece of the phrase as everything worth looking for: itself, its hyphen-separated parts,
     * and a crude singular of each.
     *
     * @return list<string>
     */
    private static function variantsOf(string $piece): array
    {
        $candidates = [trim($piece, '-'), ...explode('-', $piece)];
        $variants = [];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) < self::MIN_LENGTH || \in_array($candidate, self::NOISE, true)) {
                continue;
            }

            $variants[] = $candidate;

            if (str_ends_with($candidate, 's') && mb_strlen($candidate) > self::MIN_LENGTH) {
                $variants[] = mb_substr($candidate, 0, -1);
            }
        }

        return $variants;
    }
}
