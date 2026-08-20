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
 * The card set to render is NOT selected by scraping the model's prose for
 * ids — a natural reply like "The Alloy Bottle Cage costs €12.90" names no id
 * at all, and instructing the model to speak in ids instead of plain language
 * is bad UX that models do not reliably follow anyway. Prose scraping stays,
 * but only for two narrower jobs: (a) invention detection — a candidate id
 * that does not appear in the turn's retrieval is recorded via {@see
 * FactRenderer::validate()} as invented rather than silently ignored, whether
 * hallucinated or planted by a prompt injection; and (b) an optional
 * narrowing — if the model *does* happen to name a valid retrieved id (from
 * this batch or an earlier one this turn), that id alone is rendered instead
 * of the default.
 *
 * The card set's default source is {@see FactRenderer::lastRetrievedBatch()}:
 * whatever the most recent tool call actually returned. This is used whenever
 * prose scraping accepts no id — either because the prose named none, or
 * because everything it named was invented — so an invented id is never
 * rendered and an honest "no results" tool response renders nothing rather
 * than falling back to some earlier batch.
 *
 * Candidate ids for the invention/narrowing check come from two sources,
 * deliberately overlapping: every id this turn has already retrieved (via
 * {@see FactRenderer::retrievedIds()}) that the model's prose happens to
 * mention, plus every token in the prose that merely *looks* like a product
 * id (`fx-...`, or a 32-char hex id). The second source is what catches an id
 * the model invented outright — one that was never retrieved, so the first
 * source would never surface it.
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
        $fromProse = $validation->accepted !== [];
        $toRender = $fromProse ? $validation->accepted : $this->renderer->lastRetrievedBatch();

        $this->trace->record('grounding.select', [
            'source' => $fromProse ? 'prose' : 'last_tool_batch',
            'selectedIds' => $toRender,
        ]);

        $this->renderer->render($toRender);
        $this->renderer->unbackedPricesInProse($text);

        // The second half of the prose audit. Prices were covered from the start; availability was
        // not, and a live turn told a shopper a sold-out variant was available (ruling R75).
        $this->renderer->unbackedAvailabilityInProse($text);
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
