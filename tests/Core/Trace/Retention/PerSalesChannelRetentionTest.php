<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings;

/**
 * The regression this behaviour exists for.
 *
 * A window set on one sales channel used to be stored and then ignored: the pruner read
 * `getInt(KEY)` once with no channel, while Shopware renders the settings page with
 * `sales-channel-switchable`. A merchant could set "keep 1 day" on a channel, watch it save, and have
 * nothing change — measured on 6.7.13.1, where a three-day-old conversation survived a one-day
 * window. The dangerous direction is the one that reads as safe: a channel set to 90 days for an
 * audit trail was silently pruned at the global 30.
 *
 * Each test asserts the **whole ordered set** of passes rather than picking two out of it. That began
 * as a way around `strict-array-index-existence` — an index into a `list` is not provably there — and
 * turned out to be the better assertion: a fourth pass nobody intended, or two in the wrong order,
 * now fails instead of going unnoticed.
 */
final class PerSalesChannelRetentionTest extends RetentionTestCase
{
    /**
     * One entry per pass, in the order the pruner made them.
     *
     * Declared rather than captured by reference: a by-reference array filled inside a closure is
     * `mixed` to the analyser, and it takes every later assertion with it.
     *
     * @var list<array{channel: string|null, cutoff: string, excludesChannels: bool}>
     */
    private array $passes = [];

    public function testPrunesEachSalesChannelOnItsOwnWindow(): void
    {
        $short = Uuid::randomHex();
        $long = Uuid::randomHex();

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig
            ->method('getInt')
            ->willReturnCallback(static fn(string $key, ?string $salesChannelId = null): int => match (
                $salesChannelId
            ) {
                $short => 7,
                $long => 90,
                default => 30,
            });

        $passes = $this->passesOf(new TraceRetentionSettings($systemConfig, $this->salesChannels([$short, $long])));

        // One pass per channel on its own window, then the catch-all on the shop-wide one.
        self::assertSame([$short, $long, null], array_column($passes, 'channel'));
        self::assertSame(
            ['2026-08-14', '2026-05-23', '2026-07-22'],
            array_map(static fn(array $pass): string => substr($pass['cutoff'], 0, 10), $passes),
        );
    }

    /**
     * A conversation whose sales channel was deleted matches none of the per-channel passes, and
     * without this one it would never be pruned again — the row would outlive the channel that
     * produced it, which is the opposite of what a retention window is for.
     */
    public function testTheLastPassCoversConversationsOutsideEveryKnownChannel(): void
    {
        $known = Uuid::randomHex();

        $passes = $this->passesOf(
            new TraceRetentionSettings($this->systemConfigReturning(30), $this->salesChannels([$known])),
        );

        self::assertSame([$known, null], array_column($passes, 'channel'));
        self::assertSame([false, true], array_column($passes, 'excludesChannels'));
    }

    /**
     * Shopware rejects an `EqualsAnyFilter` with an empty list, so a shop with no sales channels at
     * all must fall through to a plain date range rather than a hard failure.
     */
    public function testAShopWithNoSalesChannelsStillPrunesOnTheGlobalWindow(): void
    {
        $passes = $this->passesOf(
            new TraceRetentionSettings($this->systemConfigReturning(30), $this->salesChannels([])),
        );

        self::assertSame([null], array_column($passes, 'channel'));
        self::assertSame([false], array_column($passes, 'excludesChannels'));
        self::assertSame(
            ['2026-07-22'],
            array_map(static fn(array $pass): string => substr($pass['cutoff'], 0, 10), $passes),
        );
    }

    /**
     * Every pass one prune makes, described, by answering each search with nothing.
     *
     * @return list<array{channel: string|null, cutoff: string, excludesChannels: bool}>
     */
    private function passesOf(TraceRetentionSettings $settings): array
    {
        $this->passes = [];

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria): IdSearchResult {
                $this->passes[] = self::describe($criteria);

                return new IdSearchResult(0, [], $criteria, Context::createDefaultContext());
            });

        (new TraceRetentionPruner($repository, $settings, 50))->prune(new \DateTimeImmutable(self::NOW));

        return $this->passes;
    }

    /**
     * @return array{channel: string|null, cutoff: string, excludesChannels: bool}
     */
    private static function describe(Criteria $criteria): array
    {
        $channel = null;
        $cutoff = '';
        $excludes = false;

        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === 'salesChannelId') {
                $channel = \is_string($filter->getValue()) ? $filter->getValue() : null;
            }

            if ($filter instanceof RangeFilter) {
                $cutoff = (string) $filter->getParameter(RangeFilter::LT);
            }

            $excludes = $excludes || $filter instanceof NotFilter;
        }

        self::assertNotSame('', $cutoff, 'every prune pass must carry a createdAt range');

        return ['channel' => $channel, 'cutoff' => $cutoff, 'excludesChannels' => $excludes];
    }
}
