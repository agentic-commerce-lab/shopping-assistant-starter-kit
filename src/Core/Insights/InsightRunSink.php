<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * Where a finished run goes, and where the next window's start comes from.
 *
 * Both halves are here rather than split because they are two ends of one fact: the run row's
 * `window_end` IS the next run's `window_start`, and a reader that could disagree with the writer
 * about that would produce a gap or an overlap nobody would notice for weeks.
 */
interface InsightRunSink
{
    /**
     * The end of the most recent run's window, or null before the first run ever.
     */
    public function lastWindowEnd(): ?\DateTimeImmutable;

    /** @return string the id of the written run */
    public function write(CompletedRun $run): string;
}
