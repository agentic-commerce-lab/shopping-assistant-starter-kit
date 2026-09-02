<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\FailedTurnMessage;
use Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage;

/**
 * What a shopper is told when the turn died.
 *
 * `IncompleteTurnMessage` was written on 2026-09-01 because the tool-limit sentence was always
 * English, and its docblock claimed the sentence it replaced was "the only shopper-facing sentence
 * the project hardcodes". It was not: the apology in `ShopwareChatTurnRunner`'s catch was the other
 * one, and it stayed English through the same German conversation. Same defect, same fix, one class
 * later.
 */
#[CoversClass(FailedTurnMessage::class)]
final class FailedTurnMessageTest extends TestCase
{
    public function testItSpeaksTheLanguageTheTurnWasBeingHeldIn(): void
    {
        self::assertStringContainsString('konnte', FailedTurnMessage::for('German'));
        self::assertStringContainsString('could not', FailedTurnMessage::for('English'));
    }

    /**
     * A language this project does not know falls back to English, exactly as
     * {@see \Swag\AssistantStarterKit\Core\Agent\IncompleteTurnMessage} does — a shopper must not be
     * told the shop failed in a third language.
     */
    public function testAnUnknownLanguageFallsBackToTheFallbackLanguage(): void
    {
        self::assertSame(FailedTurnMessage::for(ReplyLanguage::FALLBACK), FailedTurnMessage::for('Klingon'));
    }

    /**
     * **No product claim, in any language.** A turn that failed must not be the one that starts
     * asserting things, and it must not explain the exception to a customer either.
     */
    public function testNoVariantLeaksInternalsOrAssertsAnything(): void
    {
        foreach (['English', 'German', 'Klingon'] as $language) {
            $message = FailedTurnMessage::for($language);

            self::assertDoesNotMatchRegularExpression('/\d/', $message);
            self::assertStringNotContainsString('Exception', $message);
        }
    }
}
