<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use PHPUnit\Framework\MockObject\Generator\Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The three tables a prune touches, as doubles that remember what was asked of them.
 *
 * Split out of {@see InsightRetentionTest} for the reason
 * {@see \Swag\AssistantStarterKit\Tests\Core\Trace\FakeConversationRepositories} was split out of
 * its own test: `mago lint` fails a class over its method budget at `error` level, and building a
 * repository double is not any one test's concern. What the doubles recorded is read back off the
 * public properties; {@see CriteriaFilters} renders the searches.
 *
 * Every search answers the *first* call with its rows and every later one with nothing: the pruner
 * loops until a batch comes back empty, so a double that always answers would spin forever.
 *
 * Uses PHPUnit's own {@see Generator} rather than `TestCase::createMock()` because both that and
 * `createStub()` are `protected` and this is not a test case.
 */
final class FakeInsightRepositories
{
    /**
     * @var list<Criteria>
     */
    public array $runSearches = [];

    /**
     * @var list<Criteria>
     */
    public array $findingSearches = [];

    /**
     * @var list<array<array-key, mixed>>
     */
    public array $findingDeletes = [];

    /**
     * @var list<array<array-key, mixed>>
     */
    public array $runUpdates = [];

    /**
     * What happened, in the order it happened, across all three doubles.
     *
     * @var list<string>
     */
    public array $order = [];

    /**
     * The run table. **Deleting from it throws**, rather than being asserted against in one test:
     * `metrics` is the only reason this table exists and cannot be recomputed from conversations
     * retention has already deleted (D23), so a prune that ever deletes a run row should fail
     * wherever it is written, with the reason attached.
     *
     * @param list<string> $ids
     */
    public function runs(array $ids): EntityRepository
    {
        $repository = self::mock();
        $searches = 0;

        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria) use ($ids, &$searches): IdSearchResult {
                $this->runSearches[] = $criteria;
                $searches++;

                return self::found($searches === 1 ? $ids : [], $criteria);
            });

        $repository
            ->method('update')
            ->willReturnCallback(function (array $data): EntityWrittenContainerEvent {
                $this->runUpdates = self::rows($data);

                return self::writtenEvent();
            });

        $repository
            ->method('delete')
            ->willThrowException(
                new \LogicException('retention must never delete a run row: its counts outlive every conversation'),
            );

        return $repository;
    }

    /**
     * @param list<string> $ids
     */
    public function findings(array $ids): EntityRepository
    {
        $repository = self::mock();
        $searches = 0;

        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria) use ($ids, &$searches): IdSearchResult {
                $this->findingSearches[] = $criteria;
                $this->order[] = 'findings searched';
                $searches++;

                return self::found($searches === 1 ? $ids : [], $criteria);
            });

        $repository
            ->method('delete')
            ->willReturnCallback(function (array $data): EntityWrittenContainerEvent {
                $this->findingDeletes = self::rows($data);

                return self::writtenEvent();
            });

        return $repository;
    }

    /**
     * A conversation table with exactly one row old enough to delete, which is all the insights half
     * needs in order to have something to have been ordered after.
     */
    public function conversationsDeletingOnce(): EntityRepository
    {
        $repository = self::mock();
        $searches = 0;

        $repository
            ->method('searchIds')
            ->willReturnCallback(static function (Criteria $criteria) use (&$searches): IdSearchResult {
                $searches++;

                return self::found($searches === 1 ? [Uuid::randomHex()] : [], $criteria);
            });

        $repository
            ->method('delete')
            ->willReturnCallback(function (): EntityWrittenContainerEvent {
                $this->order[] = 'conversation deleted';

                return self::writtenEvent();
            });

        return $repository;
    }

    /**
     * A write payload as rows an assertion can compare.
     *
     * A mock callback receives it as `array<array-key, mixed>`, which the analyser refuses to treat
     * as a list of rows -- the reason this is a method rather than the `array_column()` one-liner it
     * looks like it should be.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $data): array
    {
        return array_map(static fn(mixed $row): array => \is_array($row) ? $row : [], array_values($data));
    }

    /**
     * One page of a search. `IdSearchResult` keys its rows by primary key rather than taking a list,
     * which is the whole reason this is not a one-liner.
     *
     * @param list<string> $ids
     */
    private static function found(array $ids, Criteria $criteria): IdSearchResult
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = ['primaryKey' => $id, 'data' => []];
        }

        return new IdSearchResult(\count($ids), $rows, $criteria, Context::createDefaultContext());
    }

    /**
     * The declared return type is an intersection rather than plain `EntityRepository`: every caller
     * chains `->method(...)`, which lives on `MockObject`, not on `EntityRepository`.
     */
    private static function mock(): EntityRepository&MockObject
    {
        /** @var EntityRepository&MockObject $mock */
        $mock = (new Generator())->testDouble(EntityRepository::class, true, true, callOriginalConstructor: false);

        return $mock;
    }

    /**
     * Nothing under test reads a write result, so a bare double satisfying the return type is enough.
     */
    private static function writtenEvent(): EntityWrittenContainerEvent
    {
        /** @var EntityWrittenContainerEvent<string>&Stub $stub */
        $stub = (new Generator())->testDouble(
            EntityWrittenContainerEvent::class,
            true,
            false,
            callOriginalConstructor: false,
        );

        return $stub;
    }
}
