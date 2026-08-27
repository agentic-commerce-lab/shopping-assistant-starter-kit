<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;

/**
 * What the shop DOES sell, offered to the model when a search found nothing.
 *
 * ## The failure this exists for
 *
 * Measured 2026-08-27 through the real endpoint, on a shop that sells cycling gear and has **zero**
 * products matching dress, suit, gown, formal, wedding, bridal or tuxedo:
 *
 * > "The search for wedding attire came up empty. Could you share more details about what you are
 * > looking for, such as a specific style, item type, colour, or size, so I can try different search
 * > terms for you?"
 *
 * Every rule held and the answer was still bad. It sends the shopper hunting for words that cannot
 * succeed, and implies a better phrasing exists.
 *
 * The assistant was boxed in, and the box is worth naming precisely. {@see SearchProductsTool::NO_MATCH_NOTE}
 * correctly forbids concluding the shop has none of a thing — an empty search establishes only that
 * these words matched nothing. So "we don't sell that" is off the table, rightly. But nothing told the
 * assistant what the shop *does* sell, so the only remaining move was to ask for different words.
 *
 * This supplies the missing half. **It is not the reason a category reader was first proposed** — that
 * argument was that the model could not find occasion wear without one, and measurement largely
 * refuted it. This is the argument that survived.
 *
 * ## Why it rides on the empty reply rather than being a tool of its own
 *
 * A `browse_categories` tool would cost a second round trip, and the model would have to decide to
 * call it at the one moment it has just been told nothing was found. Attaching the departments to the
 * empty reply puts them exactly where they are needed, and follows the idiom the rest of this tool
 * already uses — {@see TruncatedFamilies}, `terms_without_results`, `NO_MATCH_NOTE`: the reply
 * discloses what it does not contain.
 *
 * A tool remains the right shape for the *other* job — descending the tree to choose an aisle to search
 * inside — and nothing here forecloses it.
 *
 * ## What it must never become
 *
 * A licence to claim absence. The note tells the model to offer what the shop has; it does not tell it
 * the shop lacks what was asked for, because that still is not established.
 *
 * **Nor a licence to recite the shop at someone.** Measured on the lab shop after the first version of
 * this note: asked what to wear to a wedding, the assistant answered *"You can explore our available
 * categories instead: Jewelry, Beauty & Shoes / Movies / Music, Grocery & Computers / Sports, Music,
 * Jewelry & Movies / Tools, Outdoors & Shoes."* Every name was true and the answer was still noise —
 * none of those is where wedding attire would live, and offering them implies a hunt that cannot
 * succeed. So the note now makes relevance the model's job and truthfulness the tool's: the list is
 * facts, and offering one is a judgement it must only make when the department is plausibly the right
 * place.
 */
final class NoMatchOrientation
{
    /**
     * How many departments the model is shown.
     *
     * Twelve, and the number is a readability bound rather than a cost one — the read is one call
     * either way. The fashion catalogue has fourteen top-level nodes and the local shop seven, so this
     * truncates the larger of the two: {@see self::TRUNCATED_NOTE} is appended when it does, because a
     * bounded list nobody wrote down reads as the whole shop.
     */
    public const MAX_DEPARTMENTS = 12;

    /**
     * Replaces {@see SearchProductsTool::NO_MATCH_NOTE} when departments could be read.
     *
     * It keeps that note's prohibition verbatim — an empty search is not proof of absence — and adds
     * the instruction the old note could not give, because nothing had told the model what else there
     * was.
     */
    public const NOTE =
        'This search matched nothing. That means these words found no products, NOT that the shop has '
            . 'none of this kind — never tell the shopper the shop does not sell it. '
            . 'Do NOT ask them to describe it differently as though better words would find it: you do '
            . 'not know that they would, and sending them hunting for a phrasing that may not exist is '
            . 'worse than saying nothing was found. '
            . '"shop_sells" below lists departments this shop really has and which really hold products. '
            . 'Offer one ONLY if it is plausibly where what they asked for would live. If none of them '
            . 'plausibly is, say the search found nothing and stop — do not list departments that have '
            . 'nothing to do with the request, because inviting someone to browse at random reads as an '
            . 'answer and is not one. '
            . 'Never present this list as the shop\'s full range, never say the shop is "focused on" any '
            . 'of them, and never claim a particular product is in one.';

    public const TRUNCATED_NOTE =
        '(shop_sells is shortened — the shop has more departments than these, so do not describe it as '
            . 'the shop\'s full range)';

    private function __construct() {}

    /**
     * The empty-result half of the tool's reply: the note, and the departments when they could be read.
     *
     * Assembled here rather than in {@see SearchProductsTool} because that file sits against this
     * project's 400-line cap, and because the choice of note and the list it refers to are one decision
     * — split across two files, they are the pair that drifts.
     *
     * @return array{note: string, shop_sells?: list<string>, shop_sells_note?: string}
     */
    public static function replyFor(object $gateway, CatalogScope $scope, string $fallbackNote): array
    {
        $orientation = self::of($gateway, $scope);

        if ($orientation === null) {
            return ['note' => $fallbackNote];
        }

        $reply = ['note' => self::NOTE, 'shop_sells' => $orientation['departments']];

        if ($orientation['truncated']) {
            $reply['shop_sells_note'] = self::TRUNCATED_NOTE;
        }

        return $reply;
    }

    /**
     * The shop's top-level departments that hold products, or null when the gateway cannot describe its
     * tree.
     *
     * **One read, not two.** An earlier shape had separate `departments()` and `wasTruncated()` calls,
     * which meant two category queries on the DAL for one reply — the kind of waste that is invisible
     * until it is in a profile.
     *
     * Only departments with something in them: offering a shopper an empty aisle right after telling
     * them their search found nothing is the same disappointment twice.
     *
     * @return array{departments: list<string>, truncated: bool}|null
     */
    public static function of(object $gateway, CatalogScope $scope): ?array
    {
        if (!$gateway instanceof CategoryTreeReader) {
            return null;
        }

        $stocked = [];

        foreach ($gateway->categories(null, $scope) as $node) {
            if ($node->hasProducts) {
                $stocked[] = $node->name;
            }
        }

        if ($stocked === []) {
            return null;
        }

        return [
            'departments' => \array_slice($stocked, offset: 0, length: self::MAX_DEPARTMENTS),
            'truncated' => \count($stocked) > self::MAX_DEPARTMENTS,
        ];
    }
}
