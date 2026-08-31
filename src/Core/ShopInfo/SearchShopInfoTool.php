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
 * **The threshold is a recall floor, and the model judges relevance** — spec R3 as revised on
 * 2026-08-25 by measurement. The original design made the threshold a gate: below it, no passage at
 * all. That cannot work, and the measurement says why
 * (`docs/superpowers/reports/2026-08-25-shopinfo-threshold.md`): across three embedding models, the
 * lowest score for a question the document *answers* always fell below the highest score for one it
 * does not. The two cases that break it are structural, not incidental — an answer carried by a
 * negation ("ohne Angabe von Gruenden" answering "muss ich Gruende angeben?"), and a topical
 * near-miss that shares a document's whole vocabulary while being absent from it ("wann kommt meine
 * Bestellung an?" against a returns deadline). No single scalar puts both on the correct side.
 *
 * What all three models *do* reliably is retrieve the right passage among their top few. So the
 * scalar is used for what it can do — discard the obviously unrelated — and the relevance decision
 * goes to the one component that can read a negation. That is a real trade: it takes on exactly the
 * risk the original R3 was written to avoid, so {@see self::RELEVANCE_NOTE} travels with every
 * passage, and the `shop_info_not_in_documents` journey is what holds the line R3 used to hold.
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
     * The similarity a passage must reach to be worth showing the model at all.
     *
     * **Measured, not chosen** (spec R4), against the statutory German revocation notice with
     * `bge-m3`: the five questions the document answers scored 0.4421 to 0.7360, and the five it does
     * not scored 0.3041 to 0.5539. This value sits below the lowest answerable score with room to
     * spare, so it is a floor on *recall* — every question the document can answer gets its passage
     * through — and it discards only what is plainly unrelated (a size question at 0.33, an imprint
     * question at 0.30).
     *
     * It deliberately does **not** try to exclude the topical near-misses at 0.45 to 0.55. Nothing
     * could: they outrank a question the document genuinely answers. Those reach the model with
     * {@see self::RELEVANCE_NOTE} attached, and rejecting them is its job.
     *
     * `bge-m3` rather than either OpenAI model, on the same measurement: all three separate the
     * groups equally badly, but bge-m3 leaves the widest margin under its lowest answerable score,
     * which is what a recall floor needs. It is also multilingual, which this text is, and 1024
     * dimensions rather than 3072.
     *
     * Every score is still traced (R5), the rejected ones included, so this stays a measurement.
     */
    public const RECALL_MIN_SCORE = 0.40;

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

    /**
     * What travels with every passage the model receives.
     *
     * This is the load-bearing half of the revised R3. The passages are ranked by cosine similarity,
     * which measures topical overlap and not whether a question is answered — so on the measured
     * sample roughly half of what clears the recall floor for an unanswerable question is a near-miss
     * that reads plausible. The model has to be told that outright, because a retrieved passage looks
     * like an answer by default.
     *
     * The prohibitions are itemised for the same reason as in {@see self::NO_MATCH_NOTE}: deadline,
     * address, fee and condition are the four things a model supplies from general knowledge about
     * German consumer law when a shop's own document does not contain them, and each one would be a
     * legal statement the merchant never made.
     *
     * **The last sentence is about a gap the language rule opened.** Passages are retrieved in the
     * SALES CHANNEL's language — {@see \Swag\AssistantStarterKit\ShopInfo\CmsLegalPages} reads them
     * through the channel's own language chain, and a document exists in the language the merchant
     * wrote it in. The reply, since the prompt started following the shopper instead of the settings
     * screen, may be in a different one. So a German shopper on an English storefront can ask about
     * the revocation period and get a model's translation of the merchant's legal text.
     *
     * Translation is not a neutral act on a passage like this: it is a paraphrase whose errors are
     * invisible, in exactly the register where "14 Tage" and "14 days" are the only two words that
     * matter. The note therefore separates the two things a shopper needs — what the passage says,
     * which may be given in their language, and its wording, which may not be reproduced as though
     * the merchant had written it that way. Pointing at the page is what makes the difference
     * checkable, which is also {@see \Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool}'s
     * standing answer to R7's missing rendered source.
     */
    public const RELEVANCE_NOTE =
        'These passages were found by similarity, not by understanding, and one or more of them may '
            . 'have nothing to do with the question. Answer only from a passage that actually contains '
            . 'the answer. If none of them does, say you cannot find it in the shop information and '
            . 'point the shopper at the relevant page — do not stretch a passage to fit. Invent no '
            . 'deadline, no address, no fee and no condition, and do not answer from general knowledge '
            . 'about consumer law — erfinde nichts. '
            . 'If a passage is written in a language other than the one you are answering in, you may '
            . 'say what it means, but do not present your rendering of it as the shop\'s own wording: '
            . 'name the page it came from so the shopper can read the original.';

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
     * @return array{passages: list<array{id: string, document: string, section: string, text: string}>, total: int, note: string}
     */
    public function __invoke(string $question): array
    {
        $question = Guard::boundedString($question, self::MAX_QUESTION_CHARS, 'question') ?? '';

        $startedAt = hrtime(true);
        $passages = $this->retrieve($question);
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $accepted = [];

        foreach ($passages as $passage) {
            if ($passage->score >= self::RECALL_MIN_SCORE) {
                $accepted[] = $passage;
            }
        }

        // Spec R5: every score, the rejected ones included. This is what turned the threshold from a
        // guess into a measurement, and it is what will show whether the recall floor is set right
        // after real use — a rejected 0.39 beside an answered question is the signal to lower it.
        $this->trace->record('retrieve.shopinfo', [
            'question' => $question,
            'threshold' => self::RECALL_MIN_SCORE,
            'scores' => array_map(static fn(ShopInfoPassage $p): float => $p->score, $passages),
            'accepted' => \count($accepted),
            'ms' => $elapsedMs,
            // The passage text the model was actually given, and the only record of it.
            //
            // Two things need it. The admin trace view answers "why did it say that", and for a
            // document turn the passages ARE the answer — a list of scores without the text says a
            // retrieval happened, not what it found. And
            // {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unsupportedPeriods()}
            // audits the reply against them: a deadline the model states that no passage here
            // supports is an invention, and this is where the audit learns what "supported" means.
            //
            // Bounded by MAX_PASSAGES and the chunk size, so at most a few kilobytes — the same order
            // as the prompt this trace already stores.
            'passages' => array_map(static fn(ShopInfoPassage $p): string => $p->text, $accepted),
        ]);

        if ($accepted === []) {
            return ['passages' => [], 'total' => 0, 'note' => self::NO_MATCH_NOTE];
        }

        // A note travels with the passages too, not only with their absence. Under the revised R3 the
        // model is the one deciding whether any of these answers the question, and it cannot do that
        // job without being told that some of them probably do not.
        return [
            'passages' => self::shapeOf($accepted),
            'total' => \count($accepted),
            'note' => self::RELEVANCE_NOTE,
        ];
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
            // Queried at 0.0 rather than at the recall floor so the rejected scores reach the trace
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
