<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The two doubles both retention test classes need: a shop's sales channels, and a stored window.
 *
 * A base class rather than a trait, and that is not style. `mago analyze` cannot resolve
 * `createMock()` from inside a trait — the trait has no `TestCase` to look it up on — so a trait
 * version needs a `@method` annotation, and one annotation cannot describe a `createMock()` called
 * for two different classes. Every mock then degrades to `mixed` and takes the call sites with it.
 * The same reasoning already produced `AssistantEndpointTestCase` and `AssistantWidgetTestCase`.
 *
 * Shared rather than copied because `jscpd` is part of the quality gate, and because these two are
 * the fixture rather than the subject: the interesting difference between the subclasses is their
 * assertions.
 */
abstract class RetentionTestCase extends TestCase
{
    protected const NOW = '2026-08-21 12:00:00';

    /**
     * A shop whose sales channels are exactly these ids.
     *
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
     * One window for every channel and for the shop as a whole.
     */
    protected function systemConfigReturning(int $days): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($days);

        return $systemConfig;
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
