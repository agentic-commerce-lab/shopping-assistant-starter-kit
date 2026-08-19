<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * The seam where {@see FactRenderer} meets the framework's result type.
 *
 * Candidate ids come from two sources, deliberately overlapping: every id this
 * turn has already retrieved (via {@see FactRenderer::retrievedIds()}) that
 * the model's prose happens to mention, plus every token in the prose that
 * merely *looks* like a product id (`fx-...`, or a 32-char hex id). The second
 * source is what catches an id the model invented outright — one that was
 * never retrieved, so the first source would never surface it — and hands it
 * to {@see FactRenderer::validate()} to be recorded as invented rather than
 * silently ignored.
 *
 * Never calls {@see Output::setResult()}: the rendered cards live on the
 * request-scoped {@see FactRenderer}, which {@see AssistantRunner} reads
 * after the call returns. Inventing a custom {@see \Symfony\AI\Platform\Result\ResultInterface}
 * just to smuggle cards through the framework's return type would add a type
 * with no reason to exist.
 */
final class GroundingOutputProcessor implements OutputProcessorInterface
{
    /**
     * Matches an `fx-...`-style fixture id or a 32-character hex id (the shape
     * of a real Shopware entity id) anywhere in the model's prose, so an
     * invented id is caught regardless of whether it merely mimics an id this
     * turn actually retrieved.
     */
    private const CANDIDATE_ID_PATTERN = '/\bfx-[a-z0-9-]+\b|\b[0-9a-f]{32}\b/i';

    public function __construct(
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
    ) {}

    public function processOutput(Output $output): void
    {
        $result = $output->getResult();

        if (!$result instanceof TextResult) {
            $this->trace->record('render', ['skipped' => 'non-text result']);

            return;
        }

        $text = $result->getContent();

        $validation = $this->renderer->validate($this->extractCandidateIds($text));
        $this->renderer->render($validation->accepted);
        $this->renderer->unbackedPricesInProse($text);
    }

    /**
     * @return list<string>
     */
    private function extractCandidateIds(string $text): array
    {
        $candidates = [];

        foreach ($this->renderer->retrievedIds() as $id) {
            if (!str_contains($text, $id)) {
                continue;
            }

            $candidates[] = $id;
        }

        $matches = [];
        $found = preg_match_all(self::CANDIDATE_ID_PATTERN, $text, $matches);
        if ($found === false || $found === 0) {
            return array_values(array_unique($candidates));
        }

        foreach ($matches[0] ?? [] as $match) {
            $candidates[] = $match;
        }

        return array_values(array_unique($candidates));
    }
}
