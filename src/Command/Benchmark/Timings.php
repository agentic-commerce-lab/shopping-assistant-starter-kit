<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * Millisecond samples and the percentiles over them.
 *
 * **No clock in here.** The caller measures and hands the number over, which is what makes the
 * arithmetic testable with literals instead of with sleeps — a timing test that sleeps is slow and
 * flaky, and this project's deterministic suite is neither.
 *
 * Nearest-rank percentiles, not interpolated ones: `ceil(fraction × count)`, then that element of the
 * sorted sample. Every value reported is therefore a real measurement that actually happened, rather
 * than an average of two that did. For a benchmark whose whole purpose is "how slow does this get",
 * an interpolated p95 that no request ever experienced is the wrong kind of tidy.
 */
final class Timings
{
    /** @var list<float> */
    private array $samples = [];

    public function add(float $milliseconds): void
    {
        $this->samples[] = $milliseconds;
    }

    public function count(): int
    {
        return \count($this->samples);
    }

    public function p50(): float
    {
        return $this->percentile(0.50);
    }

    public function p95(): float
    {
        return $this->percentile(0.95);
    }

    /**
     * @throws \InvalidArgumentException when `$fraction` is outside 0..1
     */
    public function percentile(float $fraction): float
    {
        if ($fraction < 0.0 || $fraction > 1.0) {
            throw new \InvalidArgumentException(\sprintf(
                'A percentile fraction must be between 0 and 1, got %F.',
                $fraction,
            ));
        }

        if ([] === $this->samples) {
            return 0.0;
        }

        $sorted = $this->samples;
        sort($sorted);

        $rank = max(0, min((int) ceil($fraction * \count($sorted)) - 1, \count($sorted) - 1));

        // The clamp above puts `$rank` inside a list this method has already proven non-empty, so the
        // right-hand side is unreachable. It is a throw rather than a `?? 0.0` on purpose: the
        // analyzer cannot narrow a variable array index and needs *something* here, and a silent
        // zero in a benchmark is the kind of wrong number that reads as a fast one.
        return $sorted[$rank] ?? throw new \LogicException(\sprintf(
            'Rank %d is outside a sample of %d.',
            $rank,
            \count($sorted),
        ));
    }

    public function mean(): float
    {
        if ([] === $this->samples) {
            return 0.0;
        }

        return array_sum($this->samples) / \count($this->samples);
    }

    public function max(): float
    {
        return [] === $this->samples ? 0.0 : max($this->samples);
    }
}
