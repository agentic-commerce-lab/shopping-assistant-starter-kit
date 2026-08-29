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

    public function testTheGreetingIsEmptyWhenUnset(): void
    {
        // Empty rather than an English sentence baked into PHP: the fallback belongs in a snippet,
        // where it can be translated, so an unconfigured German shop greets in German.
        self::assertSame('', $this->extension($this->configured())->greeting(self::CHANNEL));
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
                'swag_assistant_greeting',
                'swag_assistant_add_to_cart_enabled',
                'swag_assistant_theme',
                'swag_assistant_context_key',
            ],
            $names,
        );
    }
}
