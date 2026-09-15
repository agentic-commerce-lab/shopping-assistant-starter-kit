<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Retries a search with each long word shortened by one character, when the words as written
 * matched nothing.
 *
 * **The failure this exists for.** A colleague asked the deployed shop for *"gloves"* and was told
 * the shop does not carry any. It carries `fx-004`, the Commuter Glove. The shop's own search box
 * has the same blind spot — measured against the live storefront, 2026-08-21:
 *
 * ```
 * /search?search=glove   -> 3 products      /search?search=gloves  -> 0
 * /search?search=light   -> 2 products      /search?search=lights  -> 0
 * /search?search=bottle  -> 3 products      /search?search=bottles -> 3
 * /search?search=helmet  -> 2 products      /search?search=helmets -> 2
 * ```
 *
 * So it is not a rule, it is the keyword index: whichever plural happens to appear somewhere in the
 * indexed text works, and the rest do not. That is the merchant's search configuration and it should
 * be fixed there too — but a starter kit cannot assume every shop's index is clean, and the shopper
 * is not the one who should pay for it.
 *
 * **Why a prefix and not a plural rule.** Dropping the last character of each long word is not
 * English "strip the -s": it is the relaxation a keyword index performs natively, and it happens to
 * cover `gloves -> glove`, `lights -> light` and `Handschuhe -> Handschuh` without knowing anything
 * about any language's grammar. Words shorter than {@see self::MIN_TOKEN_LENGTH} are left alone,
 * because shortening them stops narrowing anything and starts matching everything.
 *
 * **One retry, and it only ever widens.** A single extra gateway read, with the same filters and the
 * same {@see CatalogScope}, so nothing here can reach a product the blocklist or the scope excludes.
 * Every stage after it — variant resolution, the blocklist, narrowing, {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}
 * registration — runs over the result exactly as for a first-pass search.
 *
 * **The model is told the words were changed.** See {@see self::NOTE}: a relaxed match is not the
 * exact thing the shopper typed, and presenting it as one is how "gloves" becomes a confident answer
 * about a product nobody asked for.
 */
final class RelaxedTermRetry
{
    /**
     * Below this, a word carries too little to shorten: "cap" would become "ca", which prefixes
     * half a catalogue.
     */
    private const MIN_TOKEN_LENGTH = 5;

    /**
     * How many characters to drop, in order, stopping at the first step that finds anything.
     *
     * **One character is not enough for German, and that is measured.** Against the parts catalogue
     * on 2026-09-15, the shop's own search finds nothing for five common plurals. One character
     * rescues three of them — `Bremsbeläge -> Bremsbeläg`, `Batterien -> Batterie`,
     * `Bremsscheiben -> Bremsscheibe`. It cannot rescue the other two, because German forms those
     * plurals with `-en` and the singular is two characters shorter:
     *
     * ```
     * Anlassermotoren -> Anlassermotore   0 hits     -> Anlassermotor   10 hits
     * Kettenführungen -> Kettenführunge   0 hits     -> Kettenführung     6 hits
     * ```
     *
     * This stays the prefix relaxation the class was written as, rather than becoming a plural rule
     * for one language — `-en` is simply the case that needs two characters, and Dutch, Danish and
     * German all form plurals that way. Over-shortening is bounded on both sides: the second step
     * runs only when the first returned nothing, and every candidate it finds still has to survive
     * the fragment match that narrowing applies afterwards.
     */
    private const STEPS = [1, 2];

    /**
     * What the model is told, and it is deliberately cautious.
     *
     * It says the search was widened rather than that a match was found, because the relaxation is
     * blind: `gloves -> glove` finds the right product, and some other word's shortening will one day
     * find a neighbour. Naming what was searched instead of what it means leaves the judgement where
     * the products are.
     */
    public const NOTE =
        'Nothing matched those exact words, so the search was widened by shortening '
            . 'them. These results may be near matches rather than the exact thing asked for — name what '
            . 'you actually found and let the shopper confirm it is what they meant.';

    private function __construct() {}

    /**
     * @return ?list<ProductCard> null when there is nothing to relax, so a caller can tell
     *                            "not attempted" from "attempted and still empty"
     */
    public static function search(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        CatalogScope $scope,
        TraceRecorder $trace,
    ): ?array {
        $attempted = false;

        // One character first, then two. The second step only ever runs when the first found
        // nothing, so the common case still costs exactly one extra read — see self::STEPS.
        foreach (self::STEPS as $characters) {
            $relaxed = self::relax($query->term, $characters);

            if ($relaxed === null) {
                continue;
            }

            $attempted = true;
            $cards = self::attempt($gateway, $query, $scope, $trace, $relaxed);

            if ($cards !== []) {
                return $cards;
            }
        }

        return $attempted ? [] : null;
    }

    /**
     * One relaxed read, traced. Split out so the loop above reads as the policy it is.
     *
     * @return list<ProductCard>
     */
    private static function attempt(
        CommerceGatewayInterface $gateway,
        ProductQuery $query,
        CatalogScope $scope,
        TraceRecorder $trace,
        string $relaxed,
    ): array {
        $cards = $gateway->search(
            new ProductQuery(
                term: $relaxed,
                filters: $query->filters,
                limit: $query->limit,
                sort: $query->sort,
                candidateLimit: $query->candidateLimit,
                // Carried, not dropped: this retry relaxes ONE thing, and the shopper's location
                // is not it. Leaving the category out here would abandon the aisle silently, with
                // nothing in the trace saying so — which is what `retrieve.without_category`
                // exists to say. In the shipped order SearchProductsTool has already dropped the
                // category before this runs, so in practice it is null; carrying it keeps this
                // class correct on its own terms rather than relying on that ordering.
                categoryId: $query->categoryId,
            ),
            $scope,
        );

        $trace->record('retrieve.relaxTerm', [
            'term' => $query->term,
            'relaxedTerm' => $relaxed,
            'hits' => \count($cards),
            'retainedIds' => array_map(static fn(ProductCard $card): string => $card->id, $cards),
        ]);

        return $cards;
    }

    /**
     * Null when relaxing would change nothing — no term, or every word already too short to shorten.
     * A retry that repeats the first search is a wasted read and a misleading trace event.
     */
    public static function relax(?string $term, int $characters = 1): ?string
    {
        if ($term === null || trim($term) === '') {
            return null;
        }

        $words = preg_split('/\s+/u', trim($term));

        if ($words === false) {
            return null;
        }

        // A word must still be MIN_TOKEN_LENGTH - 1 long AFTER the cut, whichever cut this is, so
        // two characters never leaves a stub that one character would have refused to create.
        $floor = self::MIN_TOKEN_LENGTH + $characters - 1;

        $relaxed = array_map(static function (string $word) use ($characters, $floor): string {
            return mb_strlen($word) >= $floor ? mb_substr($word, 0, mb_strlen($word) - $characters) : $word;
        }, $words);

        $candidate = implode(' ', $relaxed);

        return $candidate === trim($term) ? null : $candidate;
    }
}
