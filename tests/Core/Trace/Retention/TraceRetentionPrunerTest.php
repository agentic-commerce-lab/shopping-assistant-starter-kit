<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings;

final class TraceRetentionPrunerTest extends TestCase
{
    private const NOW = '2026-08-21 12:00:00';

    public function testDeletesConversationsOlderThanTheWindow(): void
    {
        $ids = [Uuid::randomHex(), Uuid::randomHex()];

        $pruner = new TraceRetentionPruner($this->repositoryReturning($ids), $this->settings(30), 50);

        self::assertSame(2, $pruner->prune(new \DateTimeImmutable(self::NOW)));
    }

    public function testFiltersOnCreatedAtAtTheWindowBoundary(): void
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria) use (&$captured): IdSearchResult {
                $captured = $criteria;

                return new IdSearchResult(0, [], $criteria, Context::createDefaultContext());
            });

        $pruner = new TraceRetentionPruner($repository, $this->settings(30), 50);
        $pruner->prune(new \DateTimeImmutable(self::NOW));

        self::assertNotNull($captured);

        $filters = $captured->getFilters();
        self::assertCount(1, $filters);

        $filter = $filters[0];
        self::assertInstanceOf(RangeFilter::class, $filter);
        // Pruning keys on `createdAt` because the migration's index on it exists for no other
        // purpose. 30 days before 2026-08-21 is 2026-07-22.
        self::assertSame('createdAt', $filter->getField());
        self::assertStringStartsWith('2026-07-22', (string) $filter->getParameter(RangeFilter::LT));
    }

    public function testDeletesNothingWhenNothingIsOldEnough(): void
    {
        $pruner = new TraceRetentionPruner($this->repositoryReturning([]), $this->settings(30), 50);

        self::assertSame(0, $pruner->prune(new \DateTimeImmutable(self::NOW)));
    }

    public function testAnUnsetWindowFallsBackToTheDefaultRatherThanDisablingThePrune(): void
    {
        // There is no "keep forever": a merchant who never opens the retention card must still get
        // pruning, because this table accumulates what shoppers typed.
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(0);

        self::assertSame(
            TraceRetentionSettings::DEFAULT_DAYS,
            (new TraceRetentionSettings($systemConfig))->retentionDays(),
        );
    }

    /**
     * @param list<string> $ids
     */
    private function repositoryReturning(array $ids): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $calls = 0;

        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria) use ($ids, &$calls): IdSearchResult {
                $calls++;

                // First pass returns the batch, second returns nothing: the pruner loops until a
                // batch comes back empty, so a stub that always answers would spin forever.
                return new IdSearchResult(
                    $calls === 1 ? \count($ids) : 0,
                    $calls === 1 ? self::searchRows($ids) : [],
                    $criteria,
                    Context::createDefaultContext(),
                );
            });

        return $repository;
    }

    /**
     * `IdSearchResult` keys its rows by primary key rather than taking a list.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{primaryKey: string, data: array<string, mixed>}>
     */
    private static function searchRows(array $ids): array
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = ['primaryKey' => $id, 'data' => []];
        }

        return $rows;
    }

    private function settings(int $days): TraceRetentionSettings
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($days);

        return new TraceRetentionSettings($systemConfig);
    }
}
