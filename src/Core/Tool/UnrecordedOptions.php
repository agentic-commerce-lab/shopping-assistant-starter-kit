<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuildResult;

/**
 * The option groups a shopper asked about that not one returned product records — computed by the
 * shop, so the model does not have to infer it and is not left hedging about it.
 *
 * ## The dead end this exists for
 *
 * Reported from staging, 2026-09-03. A shopper asked for **purple tyres**. The assistant said there
 * were none and offered to check which other colours were available; no tyre in that shop records a
 * colour at all, so the follow-up search found nothing and the turn ended on:
 *
 * > "It may be that the shop has none in stock right now, or that the search words didn't match —
 * > but I can't tell which."
 *
 * Honest, and worthless — and the shop could tell perfectly well. `properties.Colour` exists (the
 * apparel is in it) and not one tyre carries it. `VISION.md` sells the whole product on exactly this
 * — *"turn 'your AI is bad' into 'your products are missing attribute X'"* — and nothing had ever
 * computed attribute X.
 *
 * ## Why the model could not get there on its own
 *
 * Two things were in its way, and both are structural rather than a wording accident.
 *
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry::NOTE} hands it the right
 * question — *"say either that the shop records no such option for the product, or that it is not
 * offered in the value asked for — whichever its listed options show"* — but that is a **set-level**
 * conclusion to be inferred across up to eight per-product option lists, and getting it wrong in
 * either direction is a false claim.
 *
 * And {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt} forbids every absolute phrasing it
 * could have used: *"never say 'we don't sell', 'we don't carry', 'we don't have', 'the shop has
 * none' or anything like them"*. That prohibition is right about **products** — an empty search
 * proves nothing about a catalogue — and it was silently also gagging the one absolute claim the
 * shop can actually prove. The prompt now carves the attribute case out explicitly, and
 * {@see self::NOTE} is what the carve-out points at.
 *
 * ## Two shapes reach here and only one of them used to say anything
 *
 * | Asked | Facet | Before |
 * |---|---|---|
 * | `[Colour, Purple]` | exists shop-wide, no tyre in it | filter applied, narrowed to nothing, `UnmatchedOptionRetry` note |
 * | `[Print, Cat]` | in no facet of this catalogue | dropped by `QueryBuilder`, **silently** — the tyres came back as though nothing had been asked |
 *
 * The second is the worse one: a constraint the shopper stated out loud was ignored with nothing
 * recording that it had been. Both are one question to this class, because
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\QueryBuildResult::$canonicalSelections} carries
 * every selection whether its filter survived or not, so "does any returned product record this
 * group" answers both without needing to know which path was taken.
 *
 * ## What it must never become
 *
 * **A claim about the shop's range.** "No tyre here records a colour" and "this shop sells no purple
 * tyres" are different statements and only the first is established. The note keeps the second one
 * forbidden.
 *
 * **A claim about a group the products DO record.** Every tyre in the reported shop lists a `Season`
 * and none lists `Winter`; reporting that group as unrecorded would state something false about a
 * shop whose tyres all carry one. That case is left to `UnmatchedOptionRetry`, which is the note
 * that describes it correctly, and a test holds the line.
 *
 * ## The group-less limit, stated rather than hidden
 *
 * A selection the model sent as a bare value — `"purple"` with no group — has no group name to
 * report once {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver}
 * fails to place it in a facet, so nothing is disclosed for it. Naming the *value* instead would
 * read as a group ("the shop does not record purple"), and the tool's own description already tells
 * the model to send the pair whenever it knows it.
 */
final class UnrecordedOptions
{
    /**
     * What the model is told, and the permission is the load-bearing half.
     *
     * The prohibitions that stay: it may not become a statement about what the shop sells or stocks,
     * and it may not become an offer to look up values of a group that has none — that offer is
     * precisely what produced the reported dead end, because the follow-up search cannot succeed.
     */
    public const NOTE_TEMPLATE =
        'This shop does not record %s for the products below — not one of them lists it. You MAY '
            . 'say that plainly: it is a fact about this shop\'s own product data, not a guess about '
            . 'its range, and it is the most useful answer there is. Say what these products ARE '
            . 'described by, from the options each one lists. Say it with "does not record" or "does '
            . 'not list", never with "has no": one is about the shop\'s data and the other reads as a '
            . 'claim about its range. Do NOT turn it into a claim about what the shop sells or has in '
            . 'stock, do NOT offer to check which other values are available — there are none to '
            . 'check — and do NOT ask the shopper to rephrase.';

    private function __construct() {}

    /**
     * The tool's option-disclosure half: the note, and the groups it refers to.
     *
     * Assembled here rather than in {@see SearchProductsTool} for the reason
     * {@see NoMatchOrientation::replyFor()} gives for its own existence — that file sits against this
     * project's 400-line cap, and the note and the list it names are one decision that would drift if
     * split across two files.
     *
     * `$optionNote` is kept and appended rather than replaced: a search asking about two groups can
     * have one unrecorded and the other merely not stocked in the value asked for, and dropping the
     * retry's note would lose the second half. The specific diagnosis leads, mirroring the order
     * {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass} runs its retries in.
     *
     * @param list<ProductCard> $returned
     * @param QueryBuildResult  $build    read for its `canonicalSelections` and nothing else — the
     *     canonical list rather than the model's own arguments because `QueryBuilder` attributes a
     *     group to a bare option value it managed to place in a facet, so it names a group in cases
     *     the arguments do not. Taken whole rather than as that one list because
     *     {@see SearchProductsTool} sits exactly on this project's 400-line cap.
     *
     * @return array{note?: string, options_not_recorded?: list<string>}
     */
    public static function replyFor(?string $optionNote, array $returned, QueryBuildResult $build): array
    {
        $groups = self::of($returned, $build->canonicalSelections);

        if ($groups === []) {
            return $optionNote === null ? [] : ['note' => $optionNote];
        }

        $note = \sprintf(self::NOTE_TEMPLATE, self::listed($groups));

        return [
            'note' => $optionNote === null ? $note : $note . ' ' . $optionNote,
            'options_not_recorded' => $groups,
        ];
    }

    /**
     * The groups the shopper named that no returned product carries.
     *
     * Both `options` and `properties` count as recording a group: a variant axis and a descriptive
     * attribute are the same thing to a shopper asking whether the shop knows the answer, and
     * {@see ToolProductSummary} already hands the model both.
     *
     * @param list<ProductCard>      $returned
     * @param list<VariantSelection> $selections
     *
     * @return list<string>
     */
    public static function of(array $returned, array $selections): array
    {
        // An empty result is NoMatchOrientation's business, not this class's: with nothing returned
        // there is no set to establish anything about, and "no product records a colour" would be
        // vacuously true of every group ever asked for.
        if ($returned === []) {
            return [];
        }

        $asked = [];

        foreach ($selections as $selection) {
            if ($selection->group !== null) {
                $asked[$selection->group] = true;
            }
        }

        foreach ($returned as $card) {
            foreach ([...array_keys($card->options), ...array_keys($card->properties)] as $group) {
                unset($asked[(string) $group]);
            }
        }

        return array_keys($asked);
    }

    /** @param list<string> $groups */
    private static function listed(array $groups): string
    {
        return implode(' or ', $groups);
    }
}
