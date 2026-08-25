<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * A field count and a value count.
 *
 * Its own type because the measurement that matters is a *comparison* of two of them — what the probe
 * found against what survived the prompt budget — and a comparison reads best between two things of
 * the same shape. Four loose ints on one constructor said the same thing while letting a caller pass
 * "sent" where "available" belonged.
 */
final class VocabularyCounts
{
    public function __construct(
        public readonly int $fields,
        public readonly int $values,
    ) {}
}
