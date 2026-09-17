<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Storefront;

use Swag\AssistantStarterKit\Storefront\AssistantWidgetExtension;

/**
 * What the widget's templates receive once the gate has let them render.
 *
 * @see AssistantWidgetExtension
 */
final class AssistantWidgetTemplateDataTest extends AssistantWidgetTestCase
{
    public function testTheAssistantNameFallsBackWhenBlank(): void
    {
        $extension = $this->extension($this->configured([self::PREFIX . 'assistantName' => '   ']));

        self::assertSame('Shopping Assistant', $extension->assistantName(self::CHANNEL));
    }

    public function testTheAssistantNameIsUsedWhenSet(): void
    {
        $extension = $this->extension($this->configured([self::PREFIX . 'assistantName' => 'Ada']));

        self::assertSame('Ada', $extension->assistantName(self::CHANNEL));
    }

    public function testAddToCartReflectsTheGuardrail(): void
    {
        $off = $this->extension($this->configured([self::PREFIX . 'enableAddToCart' => false]));
        $on = $this->extension($this->configured());

        // A merchant who forbade the assistant to add to the cart will not accept a card that still
        // shows an add button, even though that button uses Shopware's own route.
        self::assertFalse($off->addToCartEnabled(self::CHANNEL));
        self::assertTrue($on->addToCartEnabled(self::CHANNEL));
    }

    /**
     * Off is a merchant's decision. Absent is not.
     *
     * The three chips ship as snippets, so until this switch existed the only way to drop them was
     * to blank `suggestionOne`, `suggestionTwo` and `suggestionThree` — and a snippet is one string
     * *per snippet set*, so that is three fields per language. A shop that cleared the German three
     * still served the shipped English three on its English storefront. The switch is the only
     * control that turns the row off for a sales channel outright, which is why it exists beside an
     * escape hatch that already worked.
     *
     * Read through the raw `get()` for the reason `widgetEnabled` and `enableAddToCart` already
     * document: `getBool()` returns `false` for an absent key and for a stored `false` alike, so a
     * shop that never opened the configuration form would silently lose the chips it shipped with.
     */
    public function testSuggestionsStayOnUntilAMerchantSwitchesThemOff(): void
    {
        $untouched = $this->extension($this->configured());
        $off = $this->extension($this->configured([self::PREFIX . 'showSuggestions' => false]));

        self::assertTrue($untouched->suggestionsEnabled(self::CHANNEL));
        self::assertFalse($off->suggestionsEnabled(self::CHANNEL));
    }

    /**
     * `system:config:set` stores booleans as strings, and `(bool) "false"` is `true`.
     *
     * The same cast that would have kept the widget visible after `widgetEnabled false` from the
     * console would keep the chips visible here — one `filter_var` apart, and invisible in every
     * test that only ever sets a real `false`.
     */
    public function testSuggestionsSwitchedOffFromTheConsoleStayOff(): void
    {
        $off = $this->extension($this->configured([self::PREFIX . 'showSuggestions' => 'false']));

        self::assertFalse($off->suggestionsEnabled(self::CHANNEL));
    }

    public function testItExposesTheTemplateFunctions(): void
    {
        $names = array_map(
            static fn(\Twig\TwigFunction $function): string => $function->getName(),
            $this->extension($this->configured())->getFunctions(),
        );

        self::assertSame(
            [
                'swag_assistant_widget_enabled',
                'swag_assistant_widget_name',
                'swag_assistant_suggestions_enabled',
                'swag_assistant_add_to_cart_enabled',
                'swag_assistant_theme',
                'swag_assistant_context_key',
            ],
            $names,
        );
    }
}
