<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Which sales channel a `swag:assistant:*` command runs against when the operator did not say.
 *
 * **This replaces a hard-coded id, and that is a correction rather than a feature.** All four
 * commands declared `--sales-channel` with the default
 * `01a01b4af6567284ac9eeb3616598ac3`, documented as "the Storefront sales channel of the lab
 * environment". Measured on a fresh 6.7.13.1 shop on 2026-09-02: `swag:assistant:probe --search=…`
 * died with `NoContextDataException` naming that id, and so did the other three. A default that is
 * correct in exactly one shop is wrong in every other, and it is the first thing a new reader runs.
 *
 * **Active storefront channels only.** `system:install --basic-setup` leaves a shop with a
 * Storefront *and* a Headless channel; the API one must never be the default for commands that
 * print storefront URLs and build a `SalesChannelContext` for a shopper. Inactive channels are
 * excluded for the opposite reason to {@see \Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings}:
 * there, a switched-off channel still holds data that must be pruned; here, it is not somewhere a
 * probe should be pointed by default.
 *
 * **It refuses rather than picks when a shop has two.** Guessing between two storefronts would
 * reproduce the original bug in a subtler form — output that looks right and describes the wrong
 * catalogue. The ids are in the message, so the message is the fix.
 *
 * Resolution is lazy on purpose: every caller reads it as the right-hand side of `?:`, so a command
 * given an explicit `--sales-channel` never runs this query and never hits the ambiguity error.
 */
final readonly class DefaultSalesChannel
{
    /**
     * Enough rows to know the answer is "more than one" and to name a useful handful of them.
     */
    private const AMBIGUITY_LIMIT = 10;

    /**
     * `$salesChannelRepository` is `sales_channel.repository`, read only for its ids.
     */
    public function __construct(
        private EntityRepository $salesChannelRepository,
    ) {}

    /**
     * @throws NoDefaultSalesChannelException when the shop has no active storefront channel, or
     *         more than one
     */
    public function id(): string
    {
        $ids = $this->activeStorefrontIds();

        if ($ids === []) {
            throw new NoDefaultSalesChannelException(
                'This shop has no active Storefront sales channel, so there is no default to use. '
                . 'Pass --sales-channel=<id>; "bin/console sales-channel:list" prints the ids.',
            );
        }

        if (\count($ids) > 1) {
            throw new NoDefaultSalesChannelException(\sprintf(
                'This shop has %d active Storefront sales channels, so there is no single default. '
                . 'Pass --sales-channel=<id>, one of: %s',
                \count($ids),
                implode(', ', $ids),
            ));
        }

        return $ids[0];
    }

    /**
     * @return list<string>
     */
    private function activeStorefrontIds(): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(self::AMBIGUITY_LIMIT);

        $found = $this->salesChannelRepository->searchIds($criteria, Context::createDefaultContext())->getIds();

        $ids = [];

        foreach ($found as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
