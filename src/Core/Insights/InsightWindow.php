<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * The half-open interval one nightly run covers: `[start, end)`.
 *
 * **`end` is the moment the run starts, never "now" at write time.** A conversation created while
 * the run is working belongs to the next window rather than to neither, and that is only true if
 * the boundary is fixed once at the beginning. The run can take minutes — the judge reads whole
 * conversations over the network — so this is not a theoretical gap.
 *
 * **A window that would run backwards collapses to empty rather than inverting.** Reachable by a
 * clock change or by the scheduled task running twice: an inverted interval selects every
 * conversation or none depending on which way the comparison is written downstream, and neither is
 * a night's work. Collapsing makes the run a no-op, which is the honest outcome.
 */
final readonly class InsightWindow
{
    public const FIRST_RUN_LOOKBACK_HOURS = 24;

    private function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {}

    public static function next(?\DateTimeImmutable $lastEnd, \DateTimeImmutable $now): self
    {
        $start = $lastEnd ?? $now->modify(\sprintf('-%d hours', self::FIRST_RUN_LOOKBACK_HOURS));

        if ($start > $now) {
            return new self($now, $now);
        }

        return new self($start, $now);
    }

    public function isEmpty(): bool
    {
        return $this->start >= $this->end;
    }
}
