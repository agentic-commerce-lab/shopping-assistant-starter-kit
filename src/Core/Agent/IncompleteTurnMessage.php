<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;

/**
 * What a shopper is told when a turn ran out of tool calls before it could answer.
 *
 * ## Why this is a class and not a constant
 *
 * It replaces a fixed English sentence on {@see AssistantRunner}, for two reasons measured against the
 * live shop on 2026-09-01.
 *
 * **It was always English.** Asked *"Ich suche Handschuhe für den Winter, aber nichts über 35 Euro"*,
 * the shop answered "I was not able to finish handling that request" — in a conversation it would
 * otherwise have held entirely in German. This is the only shopper-facing sentence the project
 * hardcodes; every other string literal in `Core/` is an exception message for a developer, which is
 * why nothing here needed a translator before.
 *
 * **And it ignored the cards beside it.** {@see AssistantRunner::incompleteTurn()} deliberately renders
 * `lastRetrievedBatch()` rather than discarding products the tools genuinely found — a defensible
 * choice that produced an indefensible screen: five cards, three of them above the shopper's stated
 * 35-euro ceiling, under a sentence that mentioned neither the failure's effect on them nor their
 * partial nature. They read as the answer. Naming them as a partial result costs one clause.
 *
 * ## Why not snippets
 *
 * Storefront snippets are Twig's, and `Core/` is deliberately free of the framework — the widget's
 * translated defaults live in `Resources/snippet` precisely because a template can reach them and this
 * layer cannot. So the closed language list is the same one {@see ReplyLanguage} already holds, and a
 * third language costs an entry there plus two sentences here. That is the same visible cost
 * `ReplyLanguage` documents for itself, rather than a passthrough that would "work" for every locale
 * by saying nothing.
 *
 * **Every variant is free of any product claim** — no figure, no availability. That was the point of
 * the original fixed prose and it survives unchanged: a turn that failed must not be the one that
 * starts asserting things.
 */
final class IncompleteTurnMessage
{
    /**
     * The four sentences, as constants rather than a nested map.
     *
     * A map indexed by language read more compactly and cost the analyzer its type: a `??` fallback
     * over `array<string, array{...}>` widens the element back to nullable, so the return could not be
     * proven non-null. Four constants and a `match` say the same thing and are checkable.
     */
    private const ENGLISH_BARE =
        'I was not able to finish handling that request. '
            . 'Could you narrow it down — for example, ask about one product at a time?';

    private const ENGLISH_WITH_CARDS =
        'I was not able to finish handling that request, so this is '
            . 'incomplete: what you see is only what I had found by then, and it may not match everything '
            . 'you asked for. Could you narrow it down — for example, ask about one product at a time?';

    private const GERMAN_BARE =
        'Ich konnte diese Anfrage nicht zu Ende bearbeiten. '
            . 'Kannst du sie eingrenzen — zum Beispiel auf ein Produkt?';

    private const GERMAN_WITH_CARDS =
        'Ich konnte diese Anfrage nicht zu Ende bearbeiten, das hier ist '
            . 'also unvollständig: es ist nur, was ich bis dahin gefunden hatte, und es passt vielleicht '
            . 'nicht zu allem, wonach du gefragt hast. Kannst du sie eingrenzen — zum Beispiel auf ein '
            . 'Produkt?';

    private function __construct() {}

    /**
     * @param string $language a name from {@see ReplyLanguage::names()}; anything else falls back to
     *                         {@see ReplyLanguage::FALLBACK}, so a shopper is never told the shop
     *                         failed in a language it never chose to speak
     */
    public static function for(string $language, bool $hasCards): string
    {
        // `default` rather than an explicit 'English' arm: an unknown language and the fallback language
        // must give the same sentence, and `testAnUnknownLanguageFallsBackToTheFallbackLanguage` holds
        // that against what ReplyLanguage actually falls back to, so the two cannot drift.
        return match ($language) {
            'German' => $hasCards ? self::GERMAN_WITH_CARDS : self::GERMAN_BARE,
            default => $hasCards ? self::ENGLISH_WITH_CARDS : self::ENGLISH_BARE,
        };
    }
}
