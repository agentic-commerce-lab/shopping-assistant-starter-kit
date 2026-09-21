<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductStream\Exception\NoFilterException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\EntityNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalStreamFilters;

/**
 * Turning a merchant's Dynamic Product Group into something a query can exclude.
 */
final class DalStreamFiltersTest extends TestCase
{
    public function testAGroupBecomesOneConditionEvenWhenTheMerchantGaveItSeveral(): void
    {
        // A group saying "manufacturer is X AND released before June" describes one set. Handing
        // both conditions back loose would let the caller OR them into its exclusion list, and the
        // group would then block everything by X plus everything released before June.
        $filters = (new DalStreamFilters(new RecordingStreamBuilder([
            'stream-a' => [new EqualsFilter('manufacturerId', 'x'), new EqualsFilter('active', true)],
        ])))->filtersFor(['stream-a']);

        self::assertCount(1, $filters);
        self::assertSame(['manufacturerId', 'active'], ($filters[0] ?? null)?->getFields());
    }

    public function testEachGroupStaysItsOwnCondition(): void
    {
        $filters = (new DalStreamFilters(new RecordingStreamBuilder([
            'stream-a' => [new EqualsFilter('manufacturerId', 'x')],
            'stream-b' => [new EqualsFilter('active', false)],
        ])))->filtersFor(['stream-a', 'stream-b']);

        self::assertCount(2, $filters);
    }

    public function testAGroupThatNoLongerExistsBlocksNothingRatherThanBreakingEverySearch(): void
    {
        // A merchant deletes a group and forgets the setting. Letting that exception out would
        // take down every product read in the shop — the assistant would answer nothing at all,
        // for a stale id in a field nobody remembers filling in.
        $filters = (new DalStreamFilters(
            new RecordingStreamBuilder(['stream-b' => [new EqualsFilter(
                'active',
                false,
            )]], ['stream-a' => new EntityNotFoundException('product_stream', 'stream-a')]),
        ))->filtersFor([
            'stream-a',
            'stream-b',
        ]);

        self::assertCount(1, $filters);
    }

    public function testAnEmptyGroupBlocksNothingRatherThanEverything(): void
    {
        // A group with no conditions at all matches every product. Treated as "no filters", the
        // NOT around it would exclude the entire catalogue.
        $filters = (new DalStreamFilters(
            new RecordingStreamBuilder([], [
                'stream-a' => new NoFilterException('stream-a'),
            ]),
        ))->filtersFor(['stream-a']);

        self::assertSame([], $filters);
    }

    public function testAGroupWhoseConditionsResolveToNothingIsNotTurnedIntoABlanketBlock(): void
    {
        // Same danger as above by a different route: a builder that enriches nothing leaves an
        // empty AND, which matches everything.
        $filters = (new DalStreamFilters(new RecordingStreamBuilder(['stream-a' => []])))->filtersFor(['stream-a']);

        self::assertSame([], $filters);
    }

    public function testOneTurnAsksTheDatabaseForTheSameGroupOnce(): void
    {
        // build() runs several times a turn — the search, its relaxations, two counts, the
        // variant read — and each would otherwise be another product_stream read.
        $builder = new RecordingStreamBuilder(['stream-a' => [new EqualsFilter('active', false)]]);
        $filters = new DalStreamFilters($builder);

        $filters->filtersFor(['stream-a']);
        $filters->filtersFor(['stream-a']);

        self::assertSame(1, $builder->calls['stream-a']);
    }
}
