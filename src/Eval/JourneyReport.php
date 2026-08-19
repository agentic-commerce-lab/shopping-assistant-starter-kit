<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * The outcome of running one {@see Journey} across every archetype it declares.
 *
 * `passed()` is true only when every assertion, for every archetype, met its own
 * threshold — a safety assertion in every run, a quality assertion in at least 2 of the
 * journey's 3. `summary()` renders one readable block per archetype so a failing run
 * says exactly which assertion missed, by how much, and why.
 *
 * @phpstan-type Tally array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>}
 * @phpstan-type ArchetypeRun array{archetype: string, tallies: list<Tally>}
 */
final readonly class JourneyReport
{
    /** @param list<array{archetype: string, tallies: list<array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>}>}> $archetypeRuns */
    public function __construct(
        private string $journeyId,
        private array $archetypeRuns,
    ) {}

    public function passed(): bool
    {
        foreach ($this->archetypeRuns as $run) {
            if (!$this->archetypePassed($run)) {
                return false;
            }
        }

        return true;
    }

    public function summary(): string
    {
        $blocks = array_map(fn(array $run): string => $this->archetypeBlock($run), $this->archetypeRuns);

        return implode("\n\n", $blocks);
    }

    /** @param array{archetype: string, tallies: list<array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>}>} $run */
    private function archetypePassed(array $run): bool
    {
        foreach ($run['tallies'] as $tally) {
            if ($tally['passed'] < $tally['required']) {
                return false;
            }
        }

        return true;
    }

    /** @param array{archetype: string, tallies: list<array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>}>} $run */
    private function archetypeBlock(array $run): string
    {
        $status = $this->archetypePassed($run) ? 'PASS' : 'FAIL';
        $header = \sprintf('JOURNEY %s · %s', $this->journeyId, $run['archetype']);
        $lines = [\sprintf('%s%s%s', $header, str_repeat(' ', max(1, 60 - \strlen($header))), $status)];

        foreach ($run['tallies'] as $tally) {
            $lines[] = $this->tallyLine($tally);

            foreach ($tally['details'] as $detail) {
                $lines[] = '      ' . $detail;
            }
        }

        return implode("\n", $lines);
    }

    /** @param array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>} $tally */
    private function tallyLine(array $tally): string
    {
        $mark = $tally['passed'] >= $tally['required'] ? '✓' : '✗';
        $kind = $tally['safety'] ? 'safety' : 'quality';

        return \sprintf(
            '  %s %s %d/%d  (%s, needs %d)',
            $mark,
            str_pad($tally['name'], 28),
            $tally['passed'],
            $tally['total'],
            $kind,
            $tally['required'],
        );
    }
}
