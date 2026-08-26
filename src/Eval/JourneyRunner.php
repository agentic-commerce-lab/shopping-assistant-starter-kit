<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Drives one {@see Journey} against a real LLM endpoint: for every archetype it
 * declares, {@see Journey::$runs} independent attempts (via {@see JourneyAttempt}, each
 * one completely fresh), tallied per assertion (via {@see AssertionProgress}) against
 * that assertion's own pass threshold.
 */
final class JourneyRunner
{
    private readonly JourneyAttempt $attempt;

    public function __construct(
        LlmSettings $llm,
        string $catalogFixturePath,
        ?HttpClientInterface $http = null,
        ?string $shopInfoFixturePath = null,
    ) {
        $this->attempt = new JourneyAttempt($llm, $catalogFixturePath, $http, $shopInfoFixturePath);
    }

    /**
     * @throws \Symfony\AI\Agent\Exception\ExceptionInterface propagated from
     *         {@see JourneyAttempt::run()}
     */
    public function run(Journey $journey): JourneyReport
    {
        $archetypeRuns = [];

        foreach ($journey->archetypes as $archetypeName => $phrase) {
            $archetypeRuns[] = $this->runArchetype($journey, $archetypeName, $phrase);
        }

        return new JourneyReport($journey->id, $archetypeRuns);
    }

    /**
     * @return array{archetype: string, tallies: list<array{name: string, safety: bool, passed: int, total: int, required: int, details: list<string>}>}
     *
     * @throws \Symfony\AI\Agent\Exception\ExceptionInterface propagated from
     *         {@see JourneyAttempt::run()}
     */
    private function runArchetype(Journey $journey, string $archetypeName, ?string $phrase): array
    {
        $progress = new AssertionProgress($journey->assertions);

        for ($run = 1; $run <= $journey->runs; ++$run) {
            [$turn, $trace] = $this->attempt->run($journey, $phrase);
            $progress->record($run, $turn, $trace);
        }

        return ['archetype' => $archetypeName, 'tallies' => $progress->tallies($journey->runs)];
    }
}
