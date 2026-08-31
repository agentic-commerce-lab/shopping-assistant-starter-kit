<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;

/**
 * The greeting a shopper actually sees, per language.
 *
 * **Why per language at all.** `system_config` has no translation layer — one value per key per sales
 * channel — so the single `greeting` field could only ever hold one language's sentence, and a German
 * shopper on a bilingual channel got the English one. Reported 2026-08-31 from the demo shop.
 *
 * **Why a field per language rather than a snippet override.** The translated snippet
 * (`swagAssistant.panel.defaultGreeting`) is still the default and still overridable per snippet set,
 * but it is not where a merchant looks: they look at the plugin's configuration form, and a setting
 * they cannot find is a setting that does not exist. The closed language list this resolves against
 * is the same one {@see \Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage} already holds, so a
 * third language costs a field and a line there — not a redesign.
 */
final class SystemConfigWidgetSettingsGreetingTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testAGermanStorefrontGetsTheGermanGreeting(): void
    {
        $settings = $this->settings([
            self::PREFIX . 'greetingDe' => 'Hallo, ich kann im Katalog nachsehen.',
            self::PREFIX . 'greetingEn' => 'Hi, I can look things up.',
        ]);

        self::assertSame('Hallo, ich kann im Katalog nachsehen.', $settings->greeting(self::CHANNEL, 'de-DE'));
        self::assertSame('Hi, I can look things up.', $settings->greeting(self::CHANNEL, 'en-GB'));
    }

    /**
     * The pre-existing `greeting` field keeps working. A shop that configured it before this existed
     * must not silently lose its greeting on update, and a merchant with one language should not have
     * to fill in two fields.
     */
    public function testTheLanguageNeutralGreetingStillAppliesWhenNoLanguageFieldIsSet(): void
    {
        $settings = $this->settings([self::PREFIX . 'greeting' => 'Welcome.']);

        self::assertSame('Welcome.', $settings->greeting(self::CHANNEL, 'de-DE'));
        self::assertSame('Welcome.', $settings->greeting(self::CHANNEL, 'en-GB'));
    }

    public function testALanguageSpecificGreetingWinsOverTheNeutralOne(): void
    {
        $settings = $this->settings([
            self::PREFIX . 'greeting' => 'Welcome.',
            self::PREFIX . 'greetingDe' => 'Willkommen.',
        ]);

        self::assertSame('Willkommen.', $settings->greeting(self::CHANNEL, 'de-DE'));
        // English has no field of its own here, so the neutral one is still the right answer for it.
        self::assertSame('Welcome.', $settings->greeting(self::CHANNEL, 'en-GB'));
    }

    /**
     * A locale this project knows no language for is answered in English
     * ({@see \Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage::FALLBACK}), so it must be greeted in
     * English too rather than falling through to the neutral field while the reply switches language.
     */
    public function testAnUnknownLocaleGetsTheFallbackLanguageGreeting(): void
    {
        $settings = $this->settings([
            self::PREFIX . 'greeting' => 'Neutral.',
            self::PREFIX . 'greetingEn' => 'English one.',
        ]);

        self::assertSame('English one.', $settings->greeting(self::CHANNEL, 'fr-FR'));
        self::assertSame('English one.', $settings->greeting(self::CHANNEL, null));
    }

    /**
     * Empty stays empty rather than becoming an English sentence baked into PHP: the template
     * substitutes the translated snippet, so an unconfigured German shop greets in German.
     */
    public function testNothingConfiguredIsStillEmpty(): void
    {
        self::assertSame('', $this->settings([])->greeting(self::CHANNEL, 'de-DE'));
    }

    /**
     * Whitespace is not a greeting. Without this, a field a merchant "cleared" by pressing space would
     * suppress the translated snippet and show a blank bubble.
     */
    public function testAWhitespaceOnlyLanguageFieldFallsThroughToTheNeutralOne(): void
    {
        $settings = $this->settings([
            self::PREFIX . 'greeting' => 'Welcome.',
            self::PREFIX . 'greetingDe' => '   ',
        ]);

        self::assertSame('Welcome.', $settings->greeting(self::CHANNEL, 'de-DE'));
    }

    /** @param array<string, string|int|float|bool|null> $values */
    private function settings(array $values): SystemConfigWidgetSettings
    {
        return new SystemConfigWidgetSettings(new FakeSystemConfigService($values));
    }
}
