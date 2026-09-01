<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\IncompleteTurnMessage;
use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;

/**
 * What a shopper is told when the turn ran out of tool calls.
 *
 * **Two things were wrong with the fixed sentence this replaces, both measured live on 2026-09-01.**
 *
 * It was always English. Asked *"Ich suche Handschuhe für den Winter, aber nichts über 35 Euro"*, the
 * shop answered "I was not able to finish handling that request" — in a conversation the assistant
 * would otherwise have held entirely in German. It is the only shopper-facing sentence this project
 * hardcodes; every other literal in `Core/` is an exception message for a developer.
 *
 * And it did not account for the cards beside it. `AssistantRunner::incompleteTurn()` deliberately
 * renders `lastRetrievedBatch()` so that products the tools genuinely found are not thrown away — a
 * defensible choice that produced an indefensible screen: five cards, three of them above the shopper's
 * stated 35-euro ceiling, under a sentence that mentioned none of it. The cards looked like the answer.
 * Naming them as a partial result costs one clause and removes the misreading.
 */
final class IncompleteTurnMessageTest extends TestCase
{
    public function testItSpeaksTheLanguageTheTurnWasBeingHeldIn(): void
    {
        $german = IncompleteTurnMessage::for('German', hasCards: false);
        $english = IncompleteTurnMessage::for('English', hasCards: false);

        self::assertStringContainsString('konnte', $german);
        self::assertStringNotContainsString('I was not able', $german);
        self::assertStringContainsString('not able to finish', $english);
    }

    /**
     * A language this project does not know falls back to English, exactly as
     * {@see ReplyLanguage::of()} does — a shopper must not be told the shop failed in a third language.
     */
    public function testAnUnknownLanguageFallsBackToTheFallbackLanguage(): void
    {
        self::assertSame(
            IncompleteTurnMessage::for(ReplyLanguage::FALLBACK, hasCards: false),
            IncompleteTurnMessage::for('Klingon', hasCards: false),
        );
    }

    /**
     * With cards present the sentence must say they are partial, or they read as the answer.
     */
    public function testItNamesTheCardsAsAPartialResultWhenThereAreSome(): void
    {
        $withCards = IncompleteTurnMessage::for('English', hasCards: true);
        $without = IncompleteTurnMessage::for('English', hasCards: false);

        self::assertNotSame($without, $withCards);
        self::assertStringContainsString('found by then', $withCards);
        self::assertStringNotContainsString('found by then', $without);
    }

    public function testTheGermanVariantAlsoDistinguishesTheTwoCases(): void
    {
        self::assertNotSame(
            IncompleteTurnMessage::for('German', hasCards: false),
            IncompleteTurnMessage::for('German', hasCards: true),
        );
    }

    /**
     * Every variant must stay free of any claim about a product, a price or availability — the reason
     * the original sentence was fixed prose in the first place.
     */
    public function testNoVariantClaimsAnythingAboutAProduct(): void
    {
        foreach (['German', 'English'] as $language) {
            foreach ([true, false] as $hasCards) {
                $message = IncompleteTurnMessage::for($language, $hasCards);

                self::assertDoesNotMatchRegularExpression('/\d+[.,]\d{2}|€|EUR/u', $message);
                self::assertDoesNotMatchRegularExpression('/in stock|available|verfügbar|auf Lager/iu', $message);
            }
        }
    }
}
