<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Swag\AssistantStarterKit\Command\DefaultSalesChannel;
use Swag\AssistantStarterKit\Command\NoDefaultSalesChannelException;

/**
 * The four `swag:assistant:*` commands used to default `--sales-channel` to a hard-coded id.
 *
 * Measured on 2026-09-02 on a fresh 6.7.13.1 shop: every one of them died with
 * `NoContextDataException` naming an id from the machine the constant was written on. The constant
 * was documented as "the Storefront sales channel of the lab environment", which is exactly the
 * problem — a default that works in one shop and in no other is a default that is wrong everywhere
 * it is read.
 */
#[CoversClass(DefaultSalesChannel::class)]
final class DefaultSalesChannelTest extends CommandSalesChannelTestCase
{
    public function testResolvesTheOneStorefrontChannelOfAnOrdinaryShop(): void
    {
        $resolver = new DefaultSalesChannel($this->salesChannels(['01a060cfcd4371ac9acf9447eedffb4e']));

        self::assertSame('01a060cfcd4371ac9acf9447eedffb4e', $resolver->id());
    }

    public function testRefusesToGuessBetweenTwoStorefrontChannels(): void
    {
        $resolver = new DefaultSalesChannel($this->salesChannels(['aaa', 'bbb']));

        $this->expectException(NoDefaultSalesChannelException::class);
        // Both ids, so the message is the answer rather than a pointer to another command.
        $this->expectExceptionMessageMatches('/aaa/');
        $this->expectExceptionMessageMatches('/bbb/');

        $resolver->id();
    }

    public function testSaysSoWhenTheShopHasNoActiveStorefrontChannel(): void
    {
        $resolver = new DefaultSalesChannel($this->salesChannels([]));

        $this->expectException(NoDefaultSalesChannelException::class);
        $this->expectExceptionMessageMatches('/--sales-channel/');

        $resolver->id();
    }

    /**
     * Active storefront channels only. `system:install --basic-setup` leaves a shop with a Headless
     * channel too, and a command that prints storefront URLs must never default to it.
     */
    public function testAsksOnlyForActiveStorefrontChannels(): void
    {
        $fields = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria) use (&$fields): IdSearchResult {
                foreach ($criteria->getFilters() as $filter) {
                    foreach ($filter->getFields() as $field) {
                        $fields[] = $field;
                    }
                }

                return new IdSearchResult(1, self::searchRows(['x']), $criteria, Context::createDefaultContext());
            });

        (new DefaultSalesChannel($repository))->id();

        self::assertContains('active', $fields);
        self::assertContains('typeId', $fields);
        self::assertNotSame('', Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
    }
}
