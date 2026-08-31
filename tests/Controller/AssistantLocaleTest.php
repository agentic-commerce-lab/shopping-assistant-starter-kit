<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Shopware\Core\SalesChannelRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * The storefront's own language, from the request that carries it to the two places that need it.
 *
 * **Where it comes from.** Shopware's storefront `RequestTransformer` sets
 * `SalesChannelRequest::ATTRIBUTE_DOMAIN_LOCALE` on every storefront request, from the *domain* the
 * shopper is on — which is what picks the snippets around the widget, and therefore the only honest
 * answer to "what language is this page in". The sales channel cannot answer it: one channel serves
 * several domains, and a German and an English domain routinely share one.
 *
 * **What it is used for, and what it is not.** It is the assistant's fallback language, for a first
 * message with no language in it. The reply otherwise follows the shopper — see
 * {@see \Swag\AssistantStarterKit\Tests\Core\Prompt\SystemPromptLanguageTest}.
 */
final class AssistantLocaleTest extends AssistantEndpointTestCase
{
    /** @param array<string, mixed> $body */
    private function storefrontPost(array $body, string $locale): Request
    {
        $request = $this->post($body);
        $request->attributes->set(SalesChannelRequest::ATTRIBUTE_DOMAIN_LOCALE, $locale);

        return $request;
    }

    public function testTheStorefrontsLocaleReachesTheTurnRunner(): void
    {
        $controller = $this->controller();

        $controller->chat($this->storefrontPost(['message' => 'Hallo'], 'de-DE'), $this->context());

        self::assertSame('de-DE', $this->runner->lastStorefrontLocale);
    }

    /**
     * A request the storefront's transformer never touched — the endpoint is reachable without it —
     * passes null rather than an invented default, so the one place that knows what "no locale"
     * means is the one place that decides it.
     */
    public function testARequestWithoutTheAttributePassesNoLocaleAtAll(): void
    {
        $controller = $this->controller();

        $controller->chat($this->post(['message' => 'Hello']), $this->context());

        self::assertNull($this->runner->lastStorefrontLocale);
    }

    /**
     * **The column has said `locale` since the first migration and has never held one.** It was
     * given `SalesChannelContext::getLanguageId()` — a UUID — so every stored conversation in the
     * local shop reads `locale = 2fbb5fe2e29a4d70aa5854ce7ce3e20b`, which tells a merchant reading
     * the trace view nothing at all and cannot be compared against anything.
     *
     * No migration: `VARCHAR(32)` holds `de-DE` comfortably, and nothing reads the column today —
     * which is exactly why it was able to hold the wrong thing for this long.
     */
    public function testTheConversationStoresTheLocaleRatherThanTheLanguageId(): void
    {
        $controller = $this->controller();

        $controller->chat($this->storefrontPost(['message' => 'Hallo'], 'de-DE'), $this->context());

        self::assertSame('de-DE', $this->store->lastStartedLocale);
    }
}
