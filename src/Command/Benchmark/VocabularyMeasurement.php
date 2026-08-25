<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * What the vocabulary block became on this shop, and what the whole prompt cost.
 *
 * `$sent` is what `CatalogVocabulary::renderWithStats()` returned after `CatalogVocabularyBudget` cut;
 * `$available` is what the facet probe found before it. The pair is the measurement — one number
 * alone cannot show how much of a real catalogue's vocabulary the model is actually told about, which
 * is exactly the gap phase A's Finding 1 turned out to be hiding: a block reporting `truncated: true`
 * while carrying nothing at all.
 */
final class VocabularyMeasurement
{
    public function __construct(
        public readonly VocabularyCounts $available,
        public readonly VocabularyCounts $sent,
        public readonly bool $truncated,
        public readonly PromptSize $size,
    ) {}
}
