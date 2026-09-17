<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFinding;

/**
 * One finished night, ready to be written.
 *
 * A value object rather than seven parameters on {@see InsightRunSink::write()}: the sink is an
 * interface with a test double, and a seven-argument method is one whose doubles drift from it
 * silently. It also lets `sampled` and `dropped` travel with the findings, which matters more than
 * it looks — "the judge found two problems" and "the judge found two problems in the third of the
 * night it could read" are different sentences, and only the second is honest when
 * {@see Judge\JudgeBudget} dropped conversations.
 *
 * `judgeError` being null is not the same as `findings` being empty. A quiet night and a judge that
 * never answered look identical in a dashboard and mean the opposite, so the reason is stored.
 *
 * `discardedFindings` closes the last version of that same hole. A judge can answer, be parsed, and
 * have every row refused — a quote that does not occur, a type outside the closed set, a
 * conversation outside the sample. The replay command has reported that count since it caught
 * exactly that case; until now the stored row could not, so a broken control still reached the page
 * as good news.
 *
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class CompletedRun
{
    /** @param list<JudgeFinding> $findings */
    public function __construct(
        public InsightWindow $window,
        public InsightMetrics $metrics,
        public array $findings,
        public string $sampleSeed,
        public int $sampled,
        public int $dropped,
        public int $discardedFindings,
        public ?string $judgeError,
    ) {}
}
