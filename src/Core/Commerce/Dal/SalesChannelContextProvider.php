<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The single place in this plugin allowed to reach for the request-scoped
 * {@see SalesChannelContext}.
 *
 * The context is what makes customer-group and rule-based prices correct for free, and it is
 * the one Shopware type the DAL gateway genuinely needs. Confining the lookup here keeps the
 * gateway seam's rule intact — no Shopware type in a signature above the gateway — and means
 * there is exactly one thing to change if the context ever has to come from somewhere else.
 *
 * Shopware injects the context into `frontend.*` routes automatically, so in a storefront
 * request {@see self::current()} simply reads it back off the request. A console command has no
 * request at all, which is why {@see self::use()} exists: without it the probe command could
 * not run the gateway, and the first look at a real product would have to happen through the
 * widget — i.e. only once everything else already worked.
 */
final class SalesChannelContextProvider
{
    private ?SalesChannelContext $override = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @throws \RuntimeException when no sales-channel context is available
     */
    public function current(): SalesChannelContext
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $request = $this->requestStack->getMainRequest();
        $context = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        if (!$context instanceof SalesChannelContext) {
            throw new \RuntimeException(
                'No sales-channel context available. The assistant runs inside a storefront request, '
                . 'or under a caller that supplies a context explicitly via '
                . 'SalesChannelContextProvider::use() — see the probe command.',
            );
        }

        return $context;
    }

    /**
     * Runs `$callback` with an explicitly supplied context, then restores whatever was there.
     *
     * A plain setter would leak across a request in a long-running worker; scoping it to a
     * callback makes the override impossible to forget. The previous value is restored even
     * when the callback throws, because a half-restored provider is worse than none.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function use(SalesChannelContext $context, callable $callback): mixed
    {
        $previous = $this->override;
        $this->override = $context;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }
}
