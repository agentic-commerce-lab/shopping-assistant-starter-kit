<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * A router that answers one path, for the one route {@see \Swag\AssistantStarterKit\Controller\CheckoutPayload}
 * generates.
 *
 * A stub rather than the real `Router`, which would need a loaded route collection — and what the
 * endpoint tests assert is that the response carries a URL the *shop* generated rather than a path
 * this plugin wrote down, which a fixed answer proves as well as a real one.
 *
 * Its own file rather than an anonymous class inside the test case, because Mago bounds methods per
 * class and the case had reached its limit.
 */
final class FixedCheckoutRouter implements RouterInterface
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    public function generate(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string {
        return '/checkout/confirm';
    }

    public function setContext(RequestContext $context): void {}

    public function getContext(): RequestContext
    {
        return new RequestContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return new RouteCollection();
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        return [];
    }
}
