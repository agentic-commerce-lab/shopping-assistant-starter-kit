<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\DataAbstractionLayer\StatesUpdater;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\InheritanceUpdater;
use Swag\AssistantStarterKit\Command\Seed\SeedWriter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The upsert half of {@see SeedWriter}, added for the bike seeder.
 *
 * **Measured, not anticipated.** The first live run against the staging shop wrote its categories and
 * then died on the property groups:
 *
 * ```
 * Expected command for "property_group" to be "…\Write\Command\InsertCommand".
 * (Got: …\Write\Command\UpdateCommand)
 * ```
 *
 * `create()` is an insert and nothing else. The fashion seeder never noticed, because it builds a
 * catalogue on a shop it assumes is empty and owns every id it writes. The bike seeder attaches to a
 * shop somebody already built: adding two colours to the shop's own `Colour` group is an update by
 * construction, and there is no version of that which is an insert.
 *
 * The second reason, which the same failure exposed: seeded ids are derived from names rather than
 * randomised, so a run that dies partway through has already written rows the next run would insert
 * again. With `upsert` a re-run is a repair; with `create` it is a second failure at the next step
 * along. That is worth more here than in the fashion seeder, whose recovery has always been a
 * database restore.
 */
final class SeedWriterUpsertTest extends TestCase
{
    private function writer(EntityRepository $repository): SeedWriter
    {
        return new SeedWriter(
            $repository,
            $repository,
            $repository,
            $this->createStub(InheritanceUpdater::class),
            $this->createStub(StatesUpdater::class),
        );
    }

    private function repositoryExpectingUpsert(Context $context): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('upsert')
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));
        $repository->expects(self::never())->method('create');

        return $repository;
    }

    public function testCategoriesAreUpsertedRatherThanInserted(): void
    {
        $context = Context::createDefaultContext();

        $this->writer($this->repositoryExpectingUpsert($context))->upsertCategories(
            $this->createStub(SymfonyStyle::class),
            [['id' => 'a']],
            $context,
        );
    }

    /** The one the live failure was actually about. */
    public function testPropertyGroupsAreUpsertedRatherThanInserted(): void
    {
        $context = Context::createDefaultContext();

        $this->writer($this->repositoryExpectingUpsert($context))->upsertPropertyGroups(
            $this->createStub(SymfonyStyle::class),
            [['id' => 'a']],
            $context,
        );
    }

    /**
     * Nothing at all rather than an empty write: the DAL rejects an empty payload, and "this shop
     * already has every value the catalogue needs" is a normal outcome rather than an error.
     */
    public function testAnEmptyPayloadWritesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('upsert');
        $repository->expects(self::never())->method('create');

        $this->writer($repository)->upsertPropertyGroups(
            $this->createStub(SymfonyStyle::class),
            [],
            Context::createDefaultContext(),
        );
    }
}
