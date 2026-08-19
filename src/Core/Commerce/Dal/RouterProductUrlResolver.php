<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Storefront product URLs via Shopware's own `frontend.detail.page` route (verified in
 * `vendor/shopware/storefront/Controller/ProductController.php:57`).
 *
 * Absolute rather than relative: the URL is rendered into a chat reply, where a shopper may copy
 * it out of context. Shopware rewrites the technical URL to the product's SEO URL on request, so
 * this stays correct without this class having to know anything about SEO URLs.
 */
final readonly class RouterProductUrlResolver implements ProductUrlResolver
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    public function urlFor(string $productId): string
    {
        return $this->router->generate(
            'frontend.detail.page',
            ['productId' => $productId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
