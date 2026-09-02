<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Swag\AssistantStarterKit\Command\DefaultSalesChannel;

/**
 * The `DefaultSalesChannel` double every command-input test needs.
 *
 * A base class rather than a trait, for the reason
 * {@see \Swag\AssistantStarterKit\Tests\Core\Trace\Retention\RetentionTestCase} records: `mago
 * analyze` cannot resolve `createMock()` from inside a trait, and every mock then degrades to
 * `mixed` and takes the call sites with it. Shared rather than copied because `jscpd` is part of
 * the quality gate.
 *
 * `DefaultSalesChannel` is `final readonly`, so it is built for real around a mocked
 * `sales_channel.repository` rather than mocked itself — which also means these tests exercise its
 * actual query shape instead of a stub of it.
 */
abstract class CommandSalesChannelTestCase extends TestCase
{
    /**
     * A shop whose only active Storefront channel is `$id`.
     */
    protected function defaultSalesChannel(string $id): DefaultSalesChannel
    {
        return new DefaultSalesChannel($this->salesChannels([$id]));
    }

    /**
     * @param list<string> $ids
     */
    protected function salesChannels(array $ids): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('searchIds')
            ->willReturnCallback(
                static fn(Criteria $criteria): IdSearchResult => new IdSearchResult(
                    \count($ids),
                    self::searchRows($ids),
                    $criteria,
                    Context::createDefaultContext(),
                ),
            );

        return $repository;
    }

    /**
     * `IdSearchResult` keys its rows by primary key rather than taking a list.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{primaryKey: string, data: array<string, mixed>}>
     */
    protected static function searchRows(array $ids): array
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = ['primaryKey' => $id, 'data' => []];
        }

        return $rows;
    }
}
