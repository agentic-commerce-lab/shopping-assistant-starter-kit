<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;

/**
 * What a shopper is told when the turn died inside the agent.
 *
 * The sibling of {@see IncompleteTurnMessage}, and it exists for the same reason one turn later.
 * That class was written on 2026-09-01 because the tool-limit sentence was always English, and its
 * docblock concluded it was "the only shopper-facing sentence the project hardcodes". It was not:
 * the apology in {@see ShopwareChatTurnRunner}'s catch was the other one, and it answered the same
 * German conversation in English.
 *
 * Same shape as its sibling, deliberately — constants and a `match` rather than a nested map, for
 * the analyzer reason documented there, and the same closed language list as {@see ReplyLanguage}
 * rather than a passthrough that "works" for every locale by saying nothing.
 *
 * **It says nothing about the failure.** Not the exception, not the endpoint, not "the AI provider":
 * a model or network error is not something to explain to a customer, and the message could carry
 * internals. The diagnosis goes to the trace instead — {@see FailedTurn}.
 *
 * No figure and no availability claim in any variant, for the reason its sibling states: a turn that
 * failed must not be the one that starts asserting things.
 */
final class FailedTurnMessage
{
    private const ENGLISH = 'Sorry — I could not finish that just now. Please try again in a moment.';

    private const GERMAN =
        'Entschuldigung — ich konnte das gerade nicht abschließen. Bitte versuche es in einem ' . 'Moment noch einmal.';

    private function __construct() {}

    /**
     * @param string $language a name from {@see ReplyLanguage::names()}; anything else falls back to
     *                         {@see ReplyLanguage::FALLBACK}, so a shopper is never told the shop
     *                         failed in a language it never chose to speak
     */
    public static function for(string $language): string
    {
        // `default` rather than an explicit 'English' arm, so an unknown language and the fallback
        // language cannot drift apart — the same argument its sibling documents.
        return match ($language) {
            'German' => self::GERMAN,
            default => self::ENGLISH,
        };
    }
}
