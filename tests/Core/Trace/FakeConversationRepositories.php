<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\MockObject\Generator\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Builds the two `EntityRepository` doubles {@see ConversationScopeTest} exercises the real
 * `DalConversationStore` against — one standing in for `swag_assistant_conversation`, one for
 * `swag_assistant_trace_event`. Split out of that test class purely to keep its own method count
 * under mago's `too-many-methods` budget: building a repository double is not any one test's concern.
 *
 * Uses PHPUnit's own {@see Generator} directly rather than `TestCase::createMock()` /
 * `createStub()` — both are declared `protected`, and this class is not itself a test case, so it
 * cannot call them. `Generator` is the same class those methods delegate to internally.
 */
final class FakeConversationRepositories
{
    /**
     * @param array<string, array<string, mixed>> $rows conversation rows keyed by id, mutated in
     *                                                    place by the double's `create()`/`update()`
     *                                                    so {@see ConversationScopeTest} can inspect
     *                                                    or rewrite them between calls
     */
    public static function conversations(array &$rows): EntityRepository
    {
        $repository = self::mock();

        $repository
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$rows): EntityWrittenContainerEvent {
                foreach ($data as $row) {
                    $rows[(string) $row['id']] = $row;
                }

                return self::writtenEvent();
            });

        $repository
            ->method('update')
            ->willReturnCallback(function (array $data) use (&$rows): EntityWrittenContainerEvent {
                foreach ($data as $row) {
                    $id = (string) $row['id'];
                    $rows[$id] = [...($rows[$id] ?? []), ...$row];
                }

                return self::writtenEvent();
            });

        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$rows): EntitySearchResult {
                $id = $criteria->getIds()[0] ?? null;
                $row = $id !== null ? $rows[$id] ?? null : null;
                $entities = $row === null ? [] : [FakeRepositoryRows::toConversationEntity($row)];

                return new EntitySearchResult(
                    'swag_assistant_conversation',
                    \count($entities),
                    new EntityCollection($entities),
                    null,
                    $criteria,
                    $context,
                );
            });

        return $repository;
    }

    /**
     * @param list<array<string, mixed>> $rows trace-event rows, appended to in place by the
     *                                          double's `create()`
     */
    public static function events(array &$rows): EntityRepository
    {
        $repository = self::mock();

        $repository
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$rows): EntityWrittenContainerEvent {
                foreach ($data as $row) {
                    $rows[] = $row;
                }

                return self::writtenEvent();
            });

        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$rows): EntitySearchResult {
                return FakeTraceEventSearch::run($rows, $criteria, $context);
            });

        return $repository;
    }

    private static function mock(): EntityRepository
    {
        $mock = (new Generator())->testDouble(EntityRepository::class, true, true, callOriginalConstructor: false);

        assert($mock instanceof EntityRepository);
        assert($mock instanceof MockObject);

        return $mock;
    }

    /**
     * A stub return value for `create()`/`update()`: `DalConversationStore` never reads it, so a
     * bare test double satisfying the return type is enough — no real `EntityWrittenContainerEvent`
     * is ever constructed.
     */
    private static function writtenEvent(): EntityWrittenContainerEvent
    {
        $stub = (new Generator())->testDouble(
            EntityWrittenContainerEvent::class,
            true,
            false,
            callOriginalConstructor: false,
        );

        assert($stub instanceof EntityWrittenContainerEvent);
        assert($stub instanceof Stub);

        return $stub;
    }
}
