<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;

/**
 * What a shopper is told instead of a reply that described how the assistant works.
 *
 * The third sibling of {@see FailedTurnMessage} and {@see IncompleteTurnMessage}, in the same shape
 * for the same reasons: constants and a `match` rather than a nested map, and the closed language
 * list {@see ReplyLanguage} owns rather than a passthrough that "works" for every locale by saying
 * nothing.
 *
 * **It declines and moves on.** No apology for a rule the shopper did not break, no lecture about
 * prompt injection, and no hint that something was intercepted — a sentence like "that reply was
 * blocked" is itself a disclosure about how the shop works, and it tells someone probing exactly
 * which phrasing to vary. The shopper who asked an innocent question about the shop's technology
 * gets a plain no and an offer, which is the same answer the model gave unprompted in six of the
 * nine attempts in the September corpus.
 *
 * **No figure and no availability claim in either variant**, for the reason its two siblings state:
 * a turn the server has taken over must not be the one that starts asserting things.
 */
final class WithheldReplyMessage
{
    private const ENGLISH =
        'I can\'t share how I work or what I\'m built from. I can help you find products, '
            . 'check what the shop has, or answer a question about its policies — what are you looking for?';

    private const GERMAN =
        'Wie ich funktioniere und woraus ich gebaut bin, kann ich nicht teilen. Bei Produkten, '
            . 'beim Sortiment oder bei Fragen zu den Shop-Richtlinien helfe ich gern weiter — wonach suchst du?';

    private function __construct() {}

    /**
     * @param string $language a name from {@see ReplyLanguage::names()}; anything else falls back to
     *                         {@see ReplyLanguage::FALLBACK}, so a shopper is never declined in a
     *                         language the shop never chose to speak
     */
    public static function for(string $language): string
    {
        // `default` rather than an explicit 'English' arm, so an unknown language and the fallback
        // language cannot drift apart — the argument both siblings document.
        return match ($language) {
            'German' => self::GERMAN,
            default => self::ENGLISH,
        };
    }
}
