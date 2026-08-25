<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\Guard;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Answers a question from the shop's own documents — or says nothing was found.
 *
 * **Ours rather than the library's `SimilaritySearch`** (spec R2). That tool wraps a retriever which
 * wraps the store query with defaults, so it can express neither the threshold below nor the
 * sales-channel filter, and it would emit none of this plugin's trace events — leaving document turns
 * a blind spot in the admin trace view, which is precisely where "why did it say that" matters most.
 *
 * **The threshold is the whole design.** Vector search always returns its nearest neighbour, whether
 * or not that neighbour answers the question: "can I pay with Bitcoin?" against a privacy policy
 * returns the paragraph about payment data processing, and a paraphrase of that is a confident wrong
 * answer about a payment method. Below {@see self::MIN_SCORE} there is no passage at all and the model
 * is told so — the same discipline {@see SearchProductsTool::NO_MATCH_NOTE} already enforces for
 * products.
 *
 * **The model receives the passage text and may paraphrase it** (spec R6). Note the asymmetry with
 * products, where the model gets no figures because the rendered card carries them. There is no card
 * here, so the text is the payload — which is also why R7's missing rendered source is recorded as a
 * gap: a shopper reading a paraphrase of legal text currently has no way to check it.
 */
#[AsTool(
    name: 'search_shop_info',
    description: 'Look up the shop\'s own information — terms and conditions, revocation and returns '
    . 'policy, privacy, shipping, payment terms, imprint and contact details. Use this for questions '
    . 'about how the shop operates. Never use it for products, prices or availability.',
)]
final class SearchShopInfoTool
{
    /**
     * The similarity a passage must reach to be shown to the model at all.
     *
     * **Measured, and the measurement says no value works.** See
     * `docs/superpowers/reports/2026-08-25-shopinfo-threshold.md`: against the statutory German
     * revocation notice, the lowest score for a question the document *answers* (0.3566, "muss ich
     * Gruende angeben?" — answered by the clause "ohne Angabe von Gruenden") falls **below** the
     * highest score for one it does not (0.3668, "wann kommt meine Bestellung an?"). That holds for
     * `text-embedding-3-small` and `-large`, and finer chunking widens the overlap rather than
     * closing it. The two cases are the structural failure modes of single-stage bi-encoder
     * retrieval — a negation and a topical near-miss — and they sit on opposite sides of any line a
     * single scalar could draw.
     *
     * So this value is **deliberately left at the plan's provisional guess** rather than lowered to
     * something that looks calibrated. No measured score exceeded 0.71, so as it stands this tool
     * answers nothing and always returns {@see self::NO_MATCH_NOTE}. That is the safe direction — it
     * invents nothing — but it is not a working feature, and the fix is a design decision on spec R3
     * (move relevance judgement to the model, add a reranker, or find an embedding model built for
     * German), not a smaller number here. Splitting the difference would fail in both directions and
     * ship exactly the unmeasured constant this project has spent the week removing.
     *
     * Every score is traced (R5) so the decision keeps being made from evidence.
     */
    public const MIN_SCORE = 0.75;

    /**
     * How many passages the model may receive.
     *
     * Three rather than one: a question can straddle two paragraphs of a legal text, and one rather
     * than three would make the answer depend on which of them chunked first. Not ten, because every
     * extra passage is context the model can blend into a claim no single passage makes.
     */
    public const MAX_PASSAGES = 3;

    /**
     * What the model is told when nothing cleared the threshold.
     *
     * Modelled on {@see SearchProductsTool::NO_MATCH_NOTE}: it does not merely report absence, it says
     * what absence means and what not to do with it. The prohibition is itemised — deadline, address,
     * fee, condition — because those are the four things a model will supply from general knowledge
     * about German consumer law when a shop's own document does not contain them, and each one would
     * be a legal statement the merchant never made.
     *
     * The German clause is deliberate. This shop answers in German, the note is read by a model, and
     * an instruction in the reply's own language survives paraphrase better than one in another.
     */
    public const NO_MATCH_NOTE =
        'The shop information does not cover this. Say you cannot find it in the shop information and '
            . 'point the shopper at the relevant page on the website. Invent no deadline, no address, '
            . 'no fee and no condition, and do not answer from general knowledge about consumer law — '
            . 'erfinde nichts.';

    private const MAX_QUESTION_CHARS = 500;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly PassageStore $store,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param string $question What the shopper wants to know about the shop's terms, returns,
     *                         privacy, shipping or contact details.
     *
     * @return array{passages: list<array{id: string, document: string, section: string, text: string}>, total: int, note?: string}
     */
    public function __invoke(string $question): array
    {
        $question = Guard::boundedString($question, self::MAX_QUESTION_CHARS, 'question') ?? '';

        $startedAt = hrtime(true);
        $passages = $this->retrieve($question);
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $accepted = [];

        foreach ($passages as $passage) {
            if ($passage->score >= self::MIN_SCORE) {
                $accepted[] = $passage;
            }
        }

        // Spec R5: every score, the rejected ones included. We do not yet know the right threshold,
        // and this is what makes "we would have had the answer at 0.62" visible after a week of real
        // use rather than guessed at now.
        $this->trace->record('retrieve.shopinfo', [
            'question' => $question,
            'threshold' => self::MIN_SCORE,
            'scores' => array_map(static fn(ShopInfoPassage $p): float => $p->score, $passages),
            'accepted' => \count($accepted),
            'ms' => $elapsedMs,
        ]);

        if ($accepted === []) {
            return ['passages' => [], 'total' => 0, 'note' => self::NO_MATCH_NOTE];
        }

        return ['passages' => self::shapeOf($accepted), 'total' => \count($accepted)];
    }

    /**
     * @return list<ShopInfoPassage>
     */
    private function retrieve(string $question): array
    {
        $vectors = $this->embedder->embed([$question]);

        return $this->store->query(
            $vectors[0] ?? [],
            // Spec R12: this tool's own channel and no other. Two channels genuinely have different
            // terms, and a query without this filter serves one shop's revocation notice in another.
            $this->config->salesChannelId,
            // Queried at 0.0 rather than at the threshold so the rejected scores reach the trace
            // above. Filtering in the store would make R5's record impossible to write.
            minScore: 0.0,
            limit: self::MAX_PASSAGES,
        );
    }

    /**
     * @param list<ShopInfoPassage> $passages
     *
     * @return list<array{id: string, document: string, section: string, text: string}>
     */
    private static function shapeOf(array $passages): array
    {
        $shaped = [];

        foreach ($passages as $index => $passage) {
            $shaped[] = [
                // Stable within one reply, which is all an id has to be here: it exists so the model
                // can refer to a passage without retyping its text. No score — a number the model
                // cannot interpret invites it to report confidence it has no basis for.
                'id' => \sprintf('%s#%d', $passage->documentId, $index),
                'document' => $passage->documentName,
                'section' => $passage->section,
                'text' => $passage->text,
            ];
        }

        return $shaped;
    }
}
