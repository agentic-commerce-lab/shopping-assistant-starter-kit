<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

/**
 * One of {@see RequestBudget}'s two windows, described rather than passed as five loose arguments.
 *
 * A window is meaningless without all five parts together — a limit without its interval, or an
 * interval without the name a rejection reports, cannot be reasoned about — so they travel as one
 * value and the limiter is built from it in a single place.
 */
final readonly class RateWindow
{
    /**
     * @param string $id         namespaces the counter, so two windows sharing one storage cannot
     *                           collide and spend each other's budget
     * @param string $policy     a Symfony rate-limiter policy: `sliding_window` or `fixed_window`
     * @param int    $limit      requests allowed per window; below 1 means refuse everything
     * @param int    $seconds    how long the window lasts
     * @param string $reasonCode what a rejection is called in the response body
     */
    public function __construct(
        public string $id,
        public string $policy,
        public int $limit,
        public int $seconds,
        public string $reasonCode,
    ) {}
}
