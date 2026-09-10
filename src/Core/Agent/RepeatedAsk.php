<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Whether the shopper has asked this before.
 *
 * ## Why a signal and not a fix
 *
 * The eight clearest failures in the 34-conversation review of 2026-09-09 were found by reading
 * transcripts one at a time, and every one of them announced itself the same way: **the shopper said
 * it again.** They retyped the query, rephrased it, or contradicted the reply. Three &ldquo;more
 * please&rdquo; requests that returned the same two lights; a helmet search retyped verbatim; four
 * turns of a shopper insisting a bracket was not in the product description.
 *
 * A merchant cannot read 104 replies looking for that, and should not have to. Recorded per turn it
 * is a query — *show me conversations where the shopper asked twice* — which is how a merchant finds
 * the bad turns in a corpus this size without a review like that one happening first.
 *
 * ## What it measures, and what it deliberately does not
 *
 * Word overlap against each earlier shopper message, on a normalised word set. Above
 * {@see self::SIMILAR} the ask counts as repeated, and the trace records which turn it matches and
 * how close it was.
 *
 * **What it actually detects is a topic raised again, and calling it anything more would be a
 * claim it cannot support.** The measured pairs share very little text: "show me the helmets" then
 * "which helmets do you have" have exactly one content word in common. Any measure that catches
 * that one accepts a single shared word, so the flag means "the shopper has brought this up
 * before" — which is the query a merchant wants — and the recorded overlap is there so a reader can
 * judge how close it really was.
 *
 * It does **not** try to detect pushback, contradiction or frustration. Those are language
 * judgements, they differ per language, and this corpus is four of them — a signal that fires on a
 * guess is the thing `ProseAudit`'s history warns about. A repeat is decidable, and it is present in
 * every one of the eight sessions anyway.
 *
 * **Word sets, not sequences.** "do you have helmets in M" and "helmets in M?" share every word that
 * matters and no useful order. Sets also make the measure symmetric and cheap, which matters on a
 * path that runs before every model call.
 */
final class RepeatedAsk
{
    /**
     * Overlap at which two asks count as the same one.
     *
     * The measure itself is {@see WordOverlap} — shared words over the shorter set. At a half, the
     * negative cases in the corpus stay negative: "and gloves?" after a helmet search shares
     * nothing, and a returns-policy question after a product search shares nothing. What it does
     * accept is a follow-up restating a word the shopper already used — "helmets" after "show me
     * helmets in size M" reads as a repeat, and on this corpus's evidence that is what it usually is.
     */
    private const SIMILAR = 0.5;

    private function __construct() {}

    /**
     * @return array{repeatedTurn: int, overlap: float}|null null when this ask is new
     */
    public static function in(string $message, MessageBag $history): ?array
    {
        $words = WordOverlap::words($message);

        if ($words === []) {
            return null;
        }

        $turn = 0;
        $closestTurn = 0;
        $closest = 0.0;

        foreach ($history->getMessages() as $earlier) {
            if (!$earlier instanceof UserMessage) {
                continue;
            }

            ++$turn;
            $overlap = WordOverlap::of($words, WordOverlap::words(self::textOf($earlier)));

            // The closest earlier ask wins, and the threshold is applied once at the end: the turn
            // number is what a merchant clicks through to, so reporting the first ask that merely
            // passed would send them to the wrong turn.
            if ($overlap > $closest) {
                $closest = $overlap;
                $closestTurn = $turn;
            }
        }

        return $closest >= self::SIMILAR ? ['repeatedTurn' => $closestTurn, 'overlap' => round($closest, 2)] : null;
    }

    /**
     * A user message is a list of content parts, and only the text ones say anything here.
     *
     * Read through {@see Text::getText()} rather than a property: the first attempt used
     * `property_exists()` plus a direct read, which is true for a PRIVATE property and threw on
     * every multi-turn eval the moment it ran.
     */
    private static function textOf(UserMessage $message): string
    {
        $text = '';

        foreach ($message->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= ' ' . $part->getText();
            }
        }

        return $text;
    }
}
