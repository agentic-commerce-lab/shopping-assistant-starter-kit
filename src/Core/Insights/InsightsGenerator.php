<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeBudget;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeSample;
use Swag\AssistantStarterKit\Core\Llm\LlmException;

/**
 * One night's work, in four steps, and what survives each one failing.
 *
 * ## The order is the design
 *
 * 1. **Settings.** Disabled means return before anything else happens — before a window is taken,
 *    before a conversation is read. A feature nobody enabled must cost nothing at all, not even a
 *    query (D25).
 * 2. **Window.** From the sink's last end, so there is no gap and no overlap. Taken once, at the
 *    start, because the judge reads whole conversations over a network and the run takes minutes.
 * 3. **Aggregate.** Over every conversation in the window. Free, exact, and reproducible from an
 *    export months later.
 * 4. **Judge.** Over a sample, inside a `try`.
 *
 * **Step 4's `try` is the whole reason the steps are in this order.** The aggregation is free and
 * correct; losing a night of it because a provider returned a 502 would be the worst trade in this
 * feature. So the judge's failure is recorded as a reason on the run and the counts are written
 * regardless — which is also why `judgeError` is stored rather than merely logged: a quiet night
 * and a judge that never answered look identical in a dashboard and mean the opposite.
 *
 * **An empty night still writes a row.** A missing row and a night with nothing in it are
 * indistinguishable in a trend chart, and the row of zeros is the honest one. It is also what moves
 * the window forward, so a shop that is quiet for a week does not wake up to a seven-day window.
 */
final readonly class InsightsGenerator
{
    public function __construct(
        private InsightsSettingsReader $settingsReader,
        private ConversationTraceSource $traces,
        private InsightJudge $judge,
        private InsightRunSink $sink,
    ) {}

    /** @return string|null the written run's id, or null when the feature is disabled */
    public function generate(\DateTimeImmutable $now): ?string
    {
        $settings = $this->settingsReader->forSalesChannel();

        if (!$settings->enabled) {
            return null;
        }

        $window = InsightWindow::next($this->sink->lastWindowEnd(), $now);
        $traces = $this->traces->inWindow($window);
        $metrics = InsightsAggregator::aggregate($traces);

        return $this->sink->write($this->judged($window, $metrics, $traces, $settings));
    }

    /**
     * Step 4, and the `try` that keeps step 3.
     *
     * **The seed is derived from the window, not from randomness**, which is strictly better for
     * the property it exists for: re-running the same window redraws the same conversations, so a
     * surprising night can be judged again with a different model and the difference is the model
     * rather than the sample. It also means `random_bytes()`' `RandomException` is not a failure
     * mode a nightly task has to carry.
     *
     * @param list<ConversationTrace> $traces
     */
    private function judged(
        InsightWindow $window,
        InsightMetrics $metrics,
        array $traces,
        InsightsSettings $settings,
    ): CompletedRun {
        $seed = mb_substr(sha1($window->start->format('U') . '-' . $window->end->format('U')), 0, 16);
        $fitted = JudgeBudget::fit(JudgeSample::draw($traces, $settings->samplePercent, $seed));

        if ($fitted->traces === []) {
            return new CompletedRun($window, $metrics, [], $seed, 0, $fitted->dropped, null);
        }

        try {
            $findings = $this->judge->run($metrics, $fitted->traces, $settings);
        } catch (\JsonException|LlmException $failure) {
            return new CompletedRun(
                $window,
                $metrics,
                [],
                $seed,
                \count($fitted->traces),
                $fitted->dropped,
                $failure->getMessage(),
            );
        }

        return new CompletedRun($window, $metrics, $findings, $seed, \count($fitted->traces), $fitted->dropped, null);
    }
}
