<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\SalesChannelRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * The language this storefront presents itself in, read off the request.
 *
 * Split out of {@see ChatRequest} (cyclomatic-complexity) rather than suppressed, and — as with
 * {@see PageContext} and {@see CardIdList} before it — the seam is the right one anyway. Everything
 * else `ChatRequest` does is **defensive parsing of shopper input**, because the endpoint is public.
 * This value is the opposite: Shopware's own storefront `RequestTransformer` sets it, from the domain
 * it is serving. One class asking "how much of this may I believe?" and "which domain am I on?" is
 * one class with two jobs.
 *
 * **From the domain, not the sales channel.** One channel serves several domains, routinely in
 * different languages, and this attribute is what picks the snippets rendered around the widget. The
 * channel row would answer a different question, and would answer it wrongly for every shop whose
 * German and English storefronts share a channel.
 *
 * **Never from the request body.** A client that could name its own locale could choose the language
 * the shop answers in — which is the shopper's to decide, by writing in it, and nobody else's to
 * assert.
 */
final class StorefrontLocale
{
    private function __construct() {}

    /**
     * Null for any caller Shopware's storefront transformer never touched: the console, an API
     * client, a test. "This is not a storefront request" is a fact the pipeline acts on, not a
     * missing default to be filled in here.
     */
    public static function of(Request $request): ?string
    {
        $locale = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_DOMAIN_LOCALE);

        return \is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * The same value as {@see ConversationStore::start()} takes it.
     *
     * The store's parameter is a `string`, and widening a published interface to `?string` for a
     * value with a perfectly good empty case would break every implementation of it for nothing.
     * The conversion lives here rather than at the controller's call site because what "no locale"
     * means is a property of the locale, not of the ordering the controller exists to enforce.
     */
    public static function recorded(?string $locale): string
    {
        return $locale ?? '';
    }
}
