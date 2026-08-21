<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Deletes conversations past the retention window; `ON DELETE CASCADE` takes their trace events.
 *
 * Separated from the scheduled-task handler so it can be unit-tested — the handler is a thin shell,
 * which is how Shopware's own cleanup tasks are shaped (`CleanupWebhookEventLogTaskHandler`
 * delegates to `WebhookCleanup`).
 *
 * **Batched.** One run must never hold a long transaction on a shop serving shoppers, and the first
 * prune after this ships could be large on a shop that has been running the assistant for a while.
 *
 * Keys on `createdAt` because the migration's index on it exists for no other purpose.
 */
final readonly class TraceRetentionPruner
{
    public function __construct(
        private EntityRepository $conversationRepository,
        private TraceRetentionSettings $settings,
        private int $batchSize = 100,
    ) {}

    /**
     * @return int the number of conversations deleted
     */
    public function prune(\DateTimeImmutable $now): int
    {
        $cutoff = $now->sub(new \DateInterval('P' . $this->settings->retentionDays() . 'D'));
        $context = Context::createDefaultContext();
        $deleted = 0;

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($this->batchSize);
            $criteria->addFilter(new RangeFilter('createdAt', [
                RangeFilter::LT => $cutoff->format(\DATE_ATOM),
            ]));

            $ids = $this->conversationRepository->searchIds($criteria, $context)->getIds();

            if ($ids === []) {
                return $deleted;
            }

            $this->conversationRepository->delete(array_map(static fn(mixed $id): array => [
                'id' => $id,
            ], $ids), $context);

            $deleted += \count($ids);
        }
    }
}
