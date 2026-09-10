<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The grammar of a scope-of-delivery or certification claim, and how much of it is read.
 *
 * Shared by the two readers of that grammar — {@see SuppliedFactClaimExtractor} for the prose shape
 * (*"comes with the included bracket"*) and {@see ListedFactClaims} for the list shape a bundle
 * answer always takes. It exists for the reason {@see ClaimStands} exists: two classes that must
 * key on the identical set of markers, and two copies of that set would drift apart on the first
 * new phrasing a model finds.
 */
final readonly class ClaimPhrase
{
    /**
     * The markers, in both languages the assistant answers in.
     *
     * Ordered longest-first within each family so `comes complete with` is not matched as `comes
     * with` and left pointing at the wrong words.
     */
    public const GRAMMAR = 'comes complete with|comes with|supplied with|ships with|bundled with|delivered with|includ(?:es|ed)|geliefert mit|im lieferumfang|certified(?:\s+(?:to|for|as))?|rated(?:\s+(?:to|for|as))?|approved(?:\s+(?:to|for))?|compliant with|zertifiziert(?:\s+(?:nach|für))?';

    /**
     * How many words after the marker are read as the thing being claimed.
     *
     * Four covers every measured case — `the included bracket` needs two, `rated for high-security
     * use` needs three — and stopping there is what keeps the claim's own noun from being diluted by
     * the rest of the sentence. A wider window would drag in words the shop's prose happens to
     * contain and report the claim as supported on the strength of them. The list reader uses the
     * same bound for the same reason, applied to one item instead of one sentence.
     */
    public const WORDS_AFTER = 4;

    /**
     * The phrase as a merchant should read it in the trace.
     *
     * The window stops after a fixed number of words, so it routinely ends mid-thought on a
     * conjunction or a filler — `comes with a frame mount and`. Those words are already discarded as
     * noise by {@see ClaimTokens}, so this changes nothing about what is flagged; it only stops the
     * `claims.audit` payload from reading like a truncation bug to the person it is written for.
     */
    public static function tidied(string $phrase): string
    {
        return (string) preg_replace(
            '/(?:[\s,]+(?:and|or|that|which|while|where|when|but|so|if|would|will|should|can|could|may|might'
            . '|often|usually|typically|used|using|also|both|very|more|most'
            . '|und|oder|wenn|die|der|das|is|are|the|a|an))+$/iu',
            '',
            trim($phrase),
        );
    }
}
