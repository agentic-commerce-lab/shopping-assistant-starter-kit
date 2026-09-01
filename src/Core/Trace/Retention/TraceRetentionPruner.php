<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Deletes conversations past their retention window; `ON DELETE CASCADE` takes their trace events.
 *
 * Separated from the scheduled-task handler so it can be unit-tested — the handler is a thin shell,
 * which is how Shopware's own cleanup tasks are shaped (`CleanupWebhookEventLogTaskHandler`
 * delegates to `WebhookCleanup`).
 *
 * **One pass per sales channel, plus a final catch-all.** The window is per channel because the
 * settings form says it is — see {@see TraceRetentionSettings} for the measurement that proved the
 * single global read wrong. The catch-all matters as much as the per-channel passes: a conversation
 * whose sales channel was deleted matches none of them, and without it that row would never be
 * pruned again. It is the one case where the shop-wide window is the right answer, because there is
 * no channel left to ask.
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
        $windows = $this->settings->windows();
        $deleted = 0;

        foreach ($windows as $salesChannelId => $days) {
            $deleted += $this->pruneOlderThan($now, $days, new EqualsFilter('salesChannelId', $salesChannelId));
        }

        return $deleted
        + $this->pruneOlderThan(
            $now,
            $this->settings->retentionDays(),
            self::outsideKnownChannels(array_keys($windows)),
        );
    }

    /**
     * Everything the per-channel passes above cannot reach.
     *
     * `EqualsAnyFilter` is not given an empty list: Shopware rejects one, and a shop with no sales
     * channels at all would otherwise turn this last pass into a hard failure rather than the no-op
     * it should be — every conversation is then simply out of scope of any channel and the plain
     * date range is exactly right.
     *
     * @param list<string> $knownIds
     */
    private static function outsideKnownChannels(array $knownIds): ?Filter
    {
        if ($knownIds === []) {
            return null;
        }

        return new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsAnyFilter('salesChannelId', $knownIds),
        ]);
    }

    /**
     * @return int the number of conversations deleted by this pass
     */
    private function pruneOlderThan(\DateTimeImmutable $now, int $days, ?Filter $scope): int
    {
        $cutoff = $now->sub(new \DateInterval('P' . $days . 'D'));
        $context = Context::createDefaultContext();
        $deleted = 0;

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($this->batchSize);
            $criteria->addFilter(new RangeFilter('createdAt', [
                RangeFilter::LT => $cutoff->format(\DATE_ATOM),
            ]));

            if ($scope !== null) {
                $criteria->addFilter($scope);
            }

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
