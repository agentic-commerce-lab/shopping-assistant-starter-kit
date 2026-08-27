<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\DataAbstractionLayer\StatesUpdater;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\InheritanceUpdater;
use Swag\AssistantStarterKit\Command\Seed\SeedWriter;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SeedWriterTest extends TestCase
{
    public function testWriteExceptionPropagatesAfterTheIndexingStateIsRemoved(): void
    {
        $context = Context::createDefaultContext();
        $failure = new \RuntimeException('write failed');
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(static function () use ($context, $failure): never {
                self::assertTrue($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));

                throw $failure;
            });

        $writer = new SeedWriter($this->createStub(InheritanceUpdater::class), $this->createStub(StatesUpdater::class));

        try {
            $writer->writeCategories($this->createStub(SymfonyStyle::class), $repository, [], $context);
            self::fail('Expected the repository failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));
    }

    public function testProductWriteBackfillsEveryParentAndChildWhileIndexingIsDisabled(): void
    {
        $context = Context::createDefaultContext();
        $products = [
            [
                'id' => 'parent-a',
                'children' => [['id' => 'child-a'], ['id' => 'child-b']],
            ],
            [
                'id' => 'parent-b',
            ],
        ];
        $productIds = ['parent-a', 'parent-b', 'child-a', 'child-b'];
        $calls = [];

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('create')
            ->with($products, $context)
            ->willReturnCallback(static function () use (&$calls, $context): EntityWrittenContainerEvent {
                self::assertTrue($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));
                $calls[] = 'create';

                return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
            });

        $inheritanceUpdater = $this->createMock(InheritanceUpdater::class);
        $inheritanceUpdater
            ->expects(self::once())
            ->method('update')
            ->with(ProductDefinition::ENTITY_NAME, $productIds, $context)
            ->willReturnCallback(static function () use (&$calls, $context): void {
                self::assertTrue($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));
                $calls[] = 'inheritance';
            });

        $statesUpdater = $this->createMock(StatesUpdater::class);
        $statesUpdater
            ->expects(self::once())
            ->method('update')
            ->with($productIds, $context)
            ->willReturnCallback(static function () use (&$calls, $context): void {
                self::assertTrue($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));
                $calls[] = 'states';
            });

        $writer = new SeedWriter($inheritanceUpdater, $statesUpdater);
        $writer->writeProducts($this->createStub(SymfonyStyle::class), $repository, $products, $context);

        self::assertSame(['create', 'inheritance', 'states'], $calls);
        self::assertFalse($context->hasState(EntityIndexerRegistry::DISABLE_INDEXING));
    }
}
