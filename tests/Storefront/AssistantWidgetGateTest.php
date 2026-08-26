<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Storefront;

use Swag\AssistantStarterKit\Storefront\AssistantWidgetExtension;

/**
 * Whether the widget renders at all.
 *
 * **The interesting assertions are the negative ones.** An orb that opens a panel which answers 503
 * is worse than no orb: it invites a shopper to ask a question nothing can answer, and the failure
 * reads as a broken interface rather than as the configuration problem it is. Three conditions each
 * render nothing, and each for its own reason.
 *
 * @see AssistantWidgetExtension::isEnabled()
 */
final class AssistantWidgetGateTest extends AssistantWidgetTestCase
{
    public function testTheWidgetRendersOnAConfiguredShop(): void
    {
        self::assertTrue($this->extension($this->configured())->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetIsHiddenWhenTheShopHasNoModel(): void
    {
        // The chat endpoint would answer 503 here.
        self::assertFalse($this->extension([])->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetIsHiddenWhenOnlyTheApiKeyIsMissing(): void
    {
        // Ruling R43's lesson applied to the widget: a half-configured shop is not a configured one,
        // and checking a single field is how a broken setup reaches a shopper.
        $values = $this->configured();
        unset($values[self::PREFIX . 'llmApiKey']);

        self::assertFalse($this->extension($values)->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetIsHiddenWhenTheKillSwitchIsOn(): void
    {
        self::assertFalse($this->extension($this->configured([
            self::PREFIX . 'assistantEnabled' => false,
        ]))->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetIsHiddenWhenTheMerchantSwitchedItOff(): void
    {
        self::assertFalse($this->extension($this->configured([
            self::PREFIX . 'widgetEnabled' => false,
        ]))->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetRendersWhenTheEnabledFlagWasNeverStored(): void
    {
        // A shop that never opened the config form must get the documented default, which is on.
        // `getBool()` cannot express this: it returns false for absent and for a stored false alike.
        self::assertTrue($this->extension($this->configured())->isEnabled(self::CHANNEL));
    }

    public function testAnAssistantStoppedFromTheCliActuallyHidesTheWidget(): void
    {
        // The same string trap as before, now pointed the other way — and it got more dangerous in
        // the rename, which is why it keeps a test.
        //
        // `bin/console system:config:set ... assistantEnabled false` stores the string `"false"`,
        // and `(bool) "false"` is `true`. Under the old `killSwitch` name a plain cast produced a
        // widget that hid itself on a shop that was demonstrably running: annoying, and visible
        // immediately. Under this name the same cast reads a stopped assistant as **enabled** and
        // serves it to shoppers — a merchant's deliberate stop silently ignored, in the direction
        // nobody checks. `FILTER_VALIDATE_BOOLEAN` is what keeps this passing.
        $extension = $this->extension($this->configured([self::PREFIX . 'assistantEnabled' => 'false']));

        self::assertFalse($extension->isEnabled(self::CHANNEL));
    }

    public function testAnAssistantExplicitlyEnabledFromTheCliStillRenders(): void
    {
        $extension = $this->extension($this->configured([self::PREFIX . 'assistantEnabled' => 'true']));

        self::assertTrue($extension->isEnabled(self::CHANNEL));
    }

    public function testTheWidgetIsHiddenWhenDisabledFromTheCli(): void
    {
        $extension = $this->extension($this->configured([self::PREFIX . 'widgetEnabled' => 'false']));

        self::assertFalse($extension->isEnabled(self::CHANNEL));
    }
}
