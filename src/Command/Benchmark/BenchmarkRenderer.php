<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns a {@see BenchmarkReport} into tables.
 *
 * Separate from {@see BenchmarkRunner} so the numbers can be asserted without parsing console
 * output, and so the runner's tests never depend on a column's heading. Mirrors
 * {@see \Swag\AssistantStarterKit\Command\ProbeRenderer}.
 *
 * The columns are the ones the spec's *Handoff* table names, in that order, so the output can be
 * pasted into the README as-is.
 */
final class BenchmarkRenderer
{
    public function render(SymfonyStyle $io, BenchmarkReport $report): void
    {
        $io->title(\sprintf('Assistant benchmark — %s', $report->shopLabel));

        $this->vocabulary($io, $report->vocabulary);
        $this->facetProbe($io, $report->facetProbe);
        $this->queries($io, $report->queries);
        $this->cards($io, $report->cards);

        $io->comment('Durations are milliseconds. No number here is a pass or a fail.');
    }

    private function vocabulary(SymfonyStyle $io, VocabularyMeasurement $vocabulary): void
    {
        $io->section('What the model is told');

        // Available beside sent, on one row each, because the gap between them IS the finding — a
        // table showing only what was sent looks healthy at any catalogue size.
        $io->table(['', 'fields', 'values'], [
            ['available', (string) $vocabulary->available->fields, (string) $vocabulary->available->values],
            ['sent to the model', (string) $vocabulary->sent->fields, (string) $vocabulary->sent->values],
        ]);
        $io->table(['truncated', 'vocabulary chars', 'prompt chars'], [[
            $vocabulary->truncated ? 'yes' : 'no',
            (string) $vocabulary->size->vocabularyChars,
            (string) $vocabulary->size->promptChars,
        ]]);
    }

    private function facetProbe(SymfonyStyle $io, FacetProbeMeasurement $probe): void
    {
        $io->section('Facet probe');
        $io->table(['live ms', 'instance-cache ms', 'shared-cache ms', 'shared hit'], [[
            $this->ms($probe->liveMs),
            $this->ms($probe->cachedMs),
            $this->ms($probe->sharedMs),
            $probe->sharedHit ? 'yes' : 'no (pool was cold — run again)',
        ]]);
    }

    /** @param list<QueryMeasurement> $queries */
    private function queries(SymfonyStyle $io, array $queries): void
    {
        if ([] === $queries) {
            return;
        }

        $io->section('Search, per query');
        $io->table(['term', 'runs', 'p50 ms', 'p95 ms', 'max ms', 'retrieve hits', 'narrowed to'], array_map(
            fn(QueryMeasurement $query): array => [
                $query->term,
                (string) $query->searchMs->count(),
                $this->ms($query->searchMs->p50()),
                $this->ms($query->searchMs->p95()),
                $this->ms($query->searchMs->max()),
                (string) $query->retrieveHits,
                (string) $query->narrowedTo,
            ],
            $queries,
        ));
    }

    private function cards(SymfonyStyle $io, CardEndpointMeasurement $cards): void
    {
        $io->section('Cards endpoint');
        $io->table(['ids requested', 'catalogue lookups', 'total ms'], [[
            (string) $cards->ids,
            (string) $cards->lookups,
            $this->ms($cards->totalMs),
        ]]);
    }

    private function ms(float $milliseconds): string
    {
        return \sprintf('%.1F', $milliseconds);
    }
}
