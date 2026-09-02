<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Grounding\DisclosedOptions;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;
use Swag\AssistantStarterKit\Core\ShopInfo\RetrievedPassages;
use Swag\AssistantStarterKit\Core\Tool\GivenDescriptions;
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

    /**
     * Whether this turn's result has already been grounded.
     *
     * **The framework offers the same final result several times.**
     * `Toolbox\AgentProcessor::handleToolCallsCallback()` resolves a tool round by calling
     * `Agent::call()` recursively, and every nested call runs the whole output-processor chain again.
     * This processor sits after the toolbox's, so at each nesting level it is handed the text the
     * innermost call already produced. Read off a live trace of one turn with three tool calls: the
     * `validate` / `grounding.select` / `render` triple appeared four times, with identical payloads,
     * in the same millisecond — nine of that turn's thirty rows, in the one view a merchant reads to
     * find out what happened.
     *
     * Nothing was corrupted by it, because the audits assign rather than append and the last write
     * won. What it cost was three redundant prose audits and a trace nobody could read.
     *
     * A grounding decision belongs to a turn, so it is made once per turn — keyed on
     * {@see FactRenderer::turnSequence()}, which the runner advances once per turn on every path
     * that reaches the model. Keying on the turn rather than holding a boolean means no caller has
     * to remember to reset anything: the eval harness drives several turns through one bundle and
     * needs no special case, and a future caller that does the same cannot get it wrong.
     */
    private ?int $groundedTurn = null;

    public function __construct(
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly FacetSet $facets = new FacetSet(),
    ) {}

    public function processOutput(Output $output): void
    {
        $turn = $this->renderer->turnSequence();

        if ($turn === $this->groundedTurn) {
            return;
        }

        $result = $output->getResult();

        if (!$result instanceof TextResult) {
            $this->trace->record('render', ['skipped' => 'non-text result']);

            return;
        }

        $text = $result->getContent();

        $validation = $this->renderer->validate($this->candidatesIn($text));
        $fromProse = $validation->accepted !== [];
        $toRender = $fromProse ? $validation->accepted : $this->renderer->lastRetrievedBatch();

        $this->trace->record('grounding.select', [
            'source' => $fromProse ? 'prose' : 'last_tool_batch',
            'selectedIds' => $toRender,
        ]);

        // Set before the work, not after: `render()` and the audits below are the work this guard
        // exists to do exactly once, and an exception in one of them must not invite three retries
        // that would each record the same failure.
        $this->groundedTurn = $turn;

        $this->renderer->render($toRender);
        // The passages this run handed the model, so a figure the shop's own document contains is not
        // reported as an unbacked claim. Measured 2026-08-27: a correct shipping answer came back with
        // four unbacked prices and the widget annotated it as suspect.
        $this->renderer->unbackedPricesInProse($text, RetrievedPassages::from($this->trace));

        // The second half of the prose audit. Prices were covered from the start; availability was
        // not, and a live turn told a shopper a sold-out variant was available (ruling R75).
        $this->renderer->unbackedAvailabilityInProse($text);

        // The descriptions this run handed the model, for the same reason the passages are supplied
        // above: a qualitative claim the shop's own prose makes is the shop's claim, not an invention.
        // Before this, "it has an extended rear shell" was indistinguishable from a fabrication —
        // nothing in the facet vocabulary can express it — so the model had no way to say the one true
        // thing separating two products with identical properties.
        //
        // Note what is NOT supplied: `unbackedPricesInProse()` above gets passages and never
        // descriptions. A shop document legitimately states a shipping cost; a product description
        // does not legitimately state the product's price. See ProseAudit::unbackedPrices().
        // `DisclosedOptions` beside the descriptions, and for the same reason: an option value the
        // shop itself put in front of the model — a truncated family's `families` block, or the
        // viewing line's family clause — is the shop's statement, not an invention. Without it the
        // one question those two features exist to answer ("what sizes is this in?") came back
        // correct AND annotated as suspect. Measured on the live shop 2026-09-02.
        $this->renderer->unbackedPropertiesInProse(
            $text,
            $this->facets,
            GivenDescriptions::from($this->trace),
            DisclosedOptions::from($this->trace),
        );
    }

    /**
     * Every retrieved product this reply points at, by id or by name.
     *
     * **The name half is what makes the card set match the answer.** The id half predates it and stays:
     * a model that does write an id should still have it honoured, and the audit can then attribute a
     * claim to a specific product. But the prompt forbids the model from describing how its answer is
     * displayed, so in practice it writes names — and with only the id path, `$fromProse` was always
     * false and the fallback rendered the entire last tool batch. That matched the prose only while the
     * model listed everything it found. Measured live 2026-09-01: three products named, six cards
     * rendered.
     *
     * @return list<string>
     */
    private function candidatesIn(string $text): array
    {
        return array_values(array_unique([
            ...$this->extractCandidateIds($text),
            ...ProseProductNames::idsNamedIn($text, $this->renderer->retrievedNamesById()),
        ]));
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
