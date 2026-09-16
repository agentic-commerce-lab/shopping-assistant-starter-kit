<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * What survived {@see JudgeBudget::fit()}, and how much did not.
 *
 * `dropped` travels with the traces rather than being discarded because it is the difference
 * between "the judge found two problems in the night" and "the judge found two problems in the
 * third of the night it could read". A merchant reading the first sentence when the second is true
 * has been misled by omission.
 */
final readonly class FittedSample
{
    /** @param list<ConversationTrace> $traces */
    public function __construct(
        public array $traces,
        public int $dropped,
    ) {}
}
