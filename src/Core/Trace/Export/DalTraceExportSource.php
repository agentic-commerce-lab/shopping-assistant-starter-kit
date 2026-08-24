<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Reads what an export needs out of the DAL, in two queries regardless of how many conversations
 * were asked for.
 */
final readonly class DalTraceExportSource implements TraceExportSource
{
    public function __construct(
        private EntityRepository $conversationRepository,
        private EntityRepository $salesChannelRepository,
    ) {}

    /**
     * @param list<string> $ids
     *
     * @return array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}
     */
    public function load(array $ids, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $ids));
        // Associations rather than follow-up queries: a thousand conversations would otherwise be a
        // thousand round trips. `events` is the export; `customer` is the name that goes in it.
        $criteria->addAssociation('events');
        $criteria->addAssociation('customer');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var list<ConversationEntity> $conversations */
        $conversations = array_values($this->conversationRepository->search($criteria, $context)->getElements());

        return [
            'conversations' => $conversations,
            'salesChannelNames' => $this->salesChannelNames($conversations, $context),
        ];
    }

    /**
     * @param list<ConversationEntity> $conversations
     *
     * @return array<string, string>
     */
    private function salesChannelNames(array $conversations, Context $context): array
    {
        // `sales_channel_id` is a plain string column rather than a foreign key — the same
        // limitation the list component documents — so it cannot be an association, and is looked
        // up once for the whole export rather than once per row.
        $ids = array_values(array_unique(array_map(
            static fn(ConversationEntity $conversation): string => $conversation->getSalesChannelId(),
            $conversations,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = [];

        foreach ($this->salesChannelRepository->search(new Criteria($ids), $context) as $channel) {
            if (!$channel instanceof SalesChannelEntity) {
                continue;
            }

            $names[$channel->getId()] = (string) $channel->getName();
        }

        return $names;
    }
}
