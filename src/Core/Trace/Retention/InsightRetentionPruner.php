<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * The insights half of a retention prune: what a shopper wrote goes, what was counted stays (D23).
 *
 * **The asymmetry is the design, not an optimisation.** A finding quotes a shopper, so it dies with
 * the conversation it quotes. A run's `searchTerms` is what shoppers typed, so it is emptied on the
 * same cutoff. A run's `metrics` is never touched: it names nobody, and it is the only reason that
 * table exists — a trend a merchant reads over months cannot be rebuilt from conversations retention
 * has already deleted. Anything here that emptied `metrics` would be a bug, not a stricter reading
 * of `ARCHITECTURE.md`'s "not optional".
 *
 * **Findings are found by their dead link rather than by a join.** `conversation_id` is `ON DELETE
 * SET NULL` — see {@see \Swag\AssistantStarterKit\Migration\Migration1789603200CreateAssistantInsights}
 * — so deleting a conversation orphans its findings and this pass removes the orphans. The
 * indirection is deliberate: `ON DELETE CASCADE` would make a finding that outlives its conversation
 * through a delete order nobody anticipated simply vanish, where the nullable link makes it read as a
 * finding with a dead link and still be prunable. The cost is that this pass must run *after* the
 * conversation delete, and {@see TraceRetentionPruner} owns that order.
 *
 * **Separated from {@see TraceRetentionPruner} rather than added to it** because `mago.toml` fails a
 * class over cyclomatic complexity 10 at `error` level, and two more batch loops would have pushed it
 * there. The split is also the honest shape: one class deletes rows keyed on `createdAt`, the other
 * empties one column keyed on a run window.
 *
 * **The DAL rather than a `Doctrine\DBAL\Connection`.** The plan for this work asked for two
 * set-based SQL statements, on the argument that a DAL round trip per row would turn a nightly prune
 * into a nightly outage. That argument does not hold here: these are batch calls, not per-row ones,
 * and the volumes are small — one run row per night, findings in the low hundreds at their worst
 * (see {@see \Swag\AssistantStarterKit\Core\Insights\InsightRunWriter}). Against that, raw SQL would
 * be this directory's first hand-written write, would bypass the entity cache the Administration
 * insights page reads through, and would have no test coverage at all: the retention tests here are
 * mock-based, so a SQL-only path is exactly the path nothing asserts.
 */
final readonly class InsightRetentionPruner
{
    /**
     * Repositories are untyped by collection, as in {@see TraceRetentionPruner}, so a test double
     * needs no generic gymnastics. The argument order matches
     * {@see \Swag\AssistantStarterKit\Core\Insights\InsightRunWriter} — run first, then findings —
     * because `ServiceArgumentOrderTest` cannot catch a swap between two `EntityRepository`
     * arguments wired from core container ids, and one pair reading the same way everywhere is the
     * only defence left.
     */
    public function __construct(
        private EntityRepository $runRepository,
        private EntityRepository $findingRepository,
        private int $batchSize = 100,
    ) {}

    /**
     * @param \DateTimeImmutable $cutoff the strictest retention boundary in the shop, computed by
     *                                   {@see TraceRetentionPruner::prune()} from the windows it has
     *                                   already read — asking `TraceRetentionSettings` again here
     *                                   would repeat a sales-channel query per nightly run for an
     *                                   answer that cannot have changed mid-prune
     */
    public function prune(\DateTimeImmutable $cutoff): void
    {
        $this->deleteFindingsWithoutAConversation();
        $this->forgetSearchTermsBefore($cutoff);
    }

    /**
     * Every finding whose conversation the prune above has already deleted.
     */
    private function deleteFindingsWithoutAConversation(): void
    {
        $context = Context::createDefaultContext();

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($this->batchSize);
            $criteria->addFilter(new EqualsFilter('conversationId', null));

            $ids = $this->findingRepository->searchIds($criteria, $context)->getIds();

            if ($ids === []) {
                return;
            }

            $this->findingRepository->delete(array_map(static fn(mixed $id): array => [
                'id' => $id,
            ], $ids), $context);
        }
    }

    /**
     * Empties `searchTerms` on every run whose window has fallen out of retention, and nothing else.
     *
     * **Keyed on `windowStart`, not `windowEnd`.** A window is `[lastEnd, now)` and is only nightly
     * while the task keeps running: after the scheduled task has been off for a month,
     * {@see \Swag\AssistantStarterKit\Core\Insights\InsightWindow::next()} produces one window a
     * month wide. Keying on the end of it would let terms from the beginning of that window outlive
     * the conversations they came from by the width of the window. The start is the oldest thing the
     * row can describe, so it is the only boundary that cannot be late.
     *
     * **The `searchTerms IS NOT NULL` clause is load-bearing.** An `UPDATE` does not take the row out
     * of the next search the way a `DELETE` does, so without it this loop would never see an empty
     * page and would spin forever; bounded, it would still rewrite every historical run row every
     * night and make `updated_at` a lie about when the row last meant something.
     */
    private function forgetSearchTermsBefore(\DateTimeImmutable $cutoff): void
    {
        $context = Context::createDefaultContext();

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($this->batchSize);
            $criteria->addFilter(new RangeFilter('windowStart', [
                RangeFilter::LT => $cutoff->format(\DATE_ATOM),
            ]));
            $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('searchTerms', null),
            ]));

            $ids = $this->runRepository->searchIds($criteria, $context)->getIds();

            if ($ids === []) {
                return;
            }

            $this->runRepository->update(array_map(static fn(mixed $id): array => [
                'id' => $id,
                'searchTerms' => null,
            ], $ids), $context);
        }
    }
}
