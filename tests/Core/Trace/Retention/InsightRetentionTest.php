<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Trace\Retention\InsightRetentionPruner;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings;

/**
 * D23's other half: a quote dies with its conversation, a count does not.
 *
 * **The load-bearing assertion is the negative one.** The run double in
 * {@see FakeInsightRepositories} throws if anything ever deletes a run row, and the search-terms
 * test asserts the *whole* update payload rather than one key of it — `metrics` not appearing in it
 * is the point. A change that emptied or dropped a run's counts would pass a test that only looked
 * at `searchTerms`, and would quietly destroy the one thing the insights tables exist for: a trend a
 * merchant can read over months, which cannot be recomputed from conversations retention has already
 * deleted.
 *
 * **Mock-based, like the two retention tests next to it**, and deliberately not against a database.
 * The plan for this task asked for real-database assertions on the grounds that these are assertions
 * about SQL. They are not: the prune goes through the DAL — {@see InsightRetentionPruner} says why —
 * so what is worth asserting is the criteria it builds and the payload it writes, and there is no
 * database harness in this directory to assert anything else with. A second way of testing the
 * pruner would have left the existing two tests and this one disagreeing about what a pruner is.
 */
final class InsightRetentionTest extends RetentionTestCase
{
    public function testAFindingIsDeletedWithItsConversationWhileItsRunSurvives(): void
    {
        $orphans = [Uuid::randomHex(), Uuid::randomHex()];
        $tables = new FakeInsightRepositories();

        // The survival half needs no assertion of its own: the run double throws on `delete()`.
        (new InsightRetentionPruner(
            $tables->runs([]),
            $tables->findings($orphans),
            50,
        ))->prune(new \DateTimeImmutable(self::NOW));

        self::assertSame([['id' => $orphans[0]], ['id' => $orphans[1]]], $tables->findingDeletes);
    }

    /**
     * The finding pass keys on the dead link the conversation delete leaves behind, not on a date of
     * its own: `conversation_id` is `ON DELETE SET NULL`, so a null link *is* the record that the
     * quoted conversation is gone. A date filter here would also delete findings about conversations
     * a longer per-channel window is still keeping.
     */
    public function testOrphanedFindingsAreTheOnlyFindingsLookedFor(): void
    {
        $tables = new FakeInsightRepositories();

        (new InsightRetentionPruner(
            $tables->runs([]),
            $tables->findings([]),
            50,
        ))->prune(new \DateTimeImmutable(self::NOW));

        self::assertSame([['conversationId IS NULL']], CriteriaFilters::describe($tables->findingSearches));
    }

    /**
     * The one that matters most. The payload is asserted whole: two keys, and `metrics` is not one of
     * them.
     */
    public function testSearchTermsAreEmptiedWhileTheCountsAreLeftUntouched(): void
    {
        $run = Uuid::randomHex();
        $tables = new FakeInsightRepositories();

        (new InsightRetentionPruner(
            $tables->runs([$run]),
            $tables->findings([]),
            50,
        ))->prune(new \DateTimeImmutable(self::NOW));

        self::assertSame([['id' => $run, 'searchTerms' => null]], $tables->runUpdates);
    }

    /**
     * `searchTerms IS NOT NULL` is not decoration. An `UPDATE` does not take the row out of the next
     * search the way a `DELETE` does, so without that clause the batch loop never sees an empty page
     * and never returns — and bounded, it would rewrite every historical run row every night.
     *
     * The window is keyed on `windowStart` because a window is only nightly while the task keeps
     * running: after a month with the task switched off, `InsightWindow::next()` produces one window
     * a month wide, and keying on its end would let terms from its beginning outlive the
     * conversations they came from by that month.
     */
    public function testARunIsSearchedOnlyWhileItStillCarriesTermsFromOutsideTheWindow(): void
    {
        $tables = new FakeInsightRepositories();

        // 30 days before 2026-08-21, the cutoff a default shop hands over.
        (new InsightRetentionPruner($tables->runs([]), $tables->findings([]), 50))->prune(
            new \DateTimeImmutable('2026-07-22 12:00:00'),
        );

        self::assertSame(
            [['windowStart < 2026-07-22', 'NOT (searchTerms IS NULL)']],
            CriteriaFilters::describe($tables->runSearches),
        );
    }

    /**
     * A run row aggregates every sales channel, so its terms cannot be pruned on one channel's
     * window. With a channel on 7 days and the shop on 30, the shop-wide value would leave terms
     * typed in that channel sitting in `search_terms` for 23 days after the conversations were
     * deleted — so the shortest window in force anywhere wins.
     */
    public function testTheCutoffIsTheStrictestWindowInForceAnywhereInTheShop(): void
    {
        $short = Uuid::randomHex();
        $long = Uuid::randomHex();

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig
            ->method('getInt')
            ->willReturnCallback(static fn(string $key, ?string $salesChannelId = null): int => match (
                $salesChannelId
            ) {
                $short => 7,
                $long => 90,
                default => 30,
            });

        $tables = new FakeInsightRepositories();

        $this->prune(
            $tables,
            new TraceRetentionSettings($systemConfig, $this->salesChannels([$short, $long])),
            $this->repositoryFinding([]),
        );

        // 7 days before 2026-08-21: the channel nobody would have thought to look at sets the cutoff.
        self::assertSame(
            [['windowStart < 2026-08-14', 'NOT (searchTerms IS NULL)']],
            CriteriaFilters::describe($tables->runSearches),
        );
    }

    /**
     * The order is the whole reason the finding pass can key on a null link, and it is a property of
     * {@see TraceRetentionPruner} rather than of the task handler for exactly that reason: run the
     * insights half first and tonight's orphans survive until tomorrow night.
     */
    public function testFindingsArePrunedAfterTheConversationsThatOrphanThem(): void
    {
        $tables = new FakeInsightRepositories();

        $this->prune(
            $tables,
            new TraceRetentionSettings($this->systemConfigReturning(30), $this->salesChannels([])),
            $tables->conversationsDeletingOnce(),
        );

        self::assertSame(['conversation deleted', 'findings searched'], $tables->order);
    }

    /**
     * One whole prune through the real {@see TraceRetentionPruner}, insights half included.
     */
    private function prune(
        FakeInsightRepositories $tables,
        TraceRetentionSettings $settings,
        EntityRepository $conversations,
    ): void {
        $insights = new InsightRetentionPruner($tables->runs([]), $tables->findings([]), 50);

        (new TraceRetentionPruner($conversations, $settings, $insights, 50))->prune(new \DateTimeImmutable(self::NOW));
    }
}
