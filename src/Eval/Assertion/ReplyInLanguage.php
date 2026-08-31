<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Fails a turn whose reply is not in the language the journey expected.
 *
 * **What it pins.** The system prompt closes with "answer in the language the shopper writes in".
 * That is a prose instruction, and — as ruling R76's handoff wording taught at some expense — a
 * prose instruction is only as good as the last model that read it. Before that rule existed, the
 * prompt ordered English outright and a German shopper in a German storefront was answered in
 * English beside German buttons and German card labels. This is the check that notices when the
 * replacement stops working.
 *
 * **The one assertion that reads {@see AssistantTurn::$prose} for its own sake.** Every other check
 * in this suite deliberately reads the trace or the rendered cards, because prose is the one thing
 * the grounding pipeline does not control. Here the property being asserted *is* a property of the
 * sentence: nothing on a card and nothing in the trace records what language the model chose.
 *
 * **A function-word count, not a model judge.** The rule is decided by the model, so grading it with
 * a model would be the same class of component marking its own work — non-deterministically, for
 * money, on every run of every journey. Function words are what a sentence cannot avoid and cannot
 * borrow: a German reply about a "Trail Jersey" still says *das*, *ist*, *für*; an English one still
 * says *the*, *is*, *with*. The two lists below are deliberately **disjoint** — no word appears in
 * both, and words that exist in both languages with different meanings (`in`, `so`, `was`, `man`)
 * are in neither, because a marker that both sides can score is not a marker.
 *
 * The catalogue's product names are English in every journey, German ones included. They are not
 * function words, so they score for nobody — which is exactly the property that makes this usable
 * against a shop whose prose and whose products are in different languages.
 */
final class ReplyInLanguage implements Assertion
{
    /**
     * Words that only occur in a German reply.
     *
     * @var array<string, list<string>>
     */
    private const MARKERS = [
        'German' => [
            'der',
            'die',
            'das',
            'den',
            'dem',
            'des',
            'und',
            'oder',
            'aber',
            'auch',
            'noch',
            'schon',
            'ist',
            'sind',
            'war',
            'waren',
            'hat',
            'haben',
            'habe',
            'wird',
            'werden',
            'nicht',
            'kein',
            'keine',
            'keinen',
            'ein',
            'eine',
            'einen',
            'einem',
            'einer',
            'für',
            'mit',
            'von',
            'zum',
            'zur',
            'auf',
            'aus',
            'bei',
            'nach',
            'über',
            'ich',
            'wir',
            'du',
            'dir',
            'dich',
            'sie',
            'ihn',
            'ihnen',
            'möchtest',
            'möchten',
            'kannst',
            'können',
            'soll',
            'sollen',
            'welche',
            'welchen',
            'welches',
            'gibt',
            'hier',
            'dann',
            'sehr',
            'größe',
            'farbe',
            'preis',
        ],
        'English' => [
            'the',
            'and',
            'but',
            'also',
            'still',
            'already',
            'is',
            'are',
            'were',
            'has',
            'have',
            'will',
            'would',
            'could',
            'should',
            'not',
            'none',
            'for',
            'with',
            'from',
            'about',
            'after',
            'we',
            'you',
            'your',
            'they',
            'them',
            'it',
            'its',
            'this',
            'that',
            'these',
            'those',
            'there',
            'which',
            'what',
            'here',
            'then',
            'very',
            'size',
            'colour',
            'color',
            'price',
        ],
    ];

    public function name(): string
    {
        return 'reply_in_language';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $expected = $expectations['expect'] ?? null;

        if (!\is_string($expected) || !isset(self::MARKERS[$expected])) {
            // A typo in a journey file must fail loudly rather than assert nothing, the same way
            // AssertionRegistry's default arm throws on an unknown short name.
            return $this->fail(\sprintf(
                'expected language "%s" is not one this assertion can score; known: %s',
                \is_string($expected) ? $expected : get_debug_type($expected),
                implode(', ', array_keys(self::MARKERS)),
            ));
        }

        $scores = [];
        foreach (self::MARKERS as $language => $markers) {
            $scores[$language] = self::score($turn->prose, $markers);
        }

        $best = max($scores);

        if ($best === 0) {
            // Ruling R45's shape: a check over nothing is vacuous, not satisfied. A reply with no
            // function words in it — bare product names, or no reply at all — would otherwise make
            // every language expectation green at once.
            return $this->fail(
                'the reply carries no function words in any known language, so its language cannot be told',
            );
        }

        if ($scores[$expected] < $best) {
            return $this->fail(\sprintf(
                'the reply reads as %s (%s), not %s',
                (string) array_search($best, $scores, true),
                self::tally($scores),
                $expected,
            ));
        }

        return new AssertionResult(
            $this->name(),
            true,
            \sprintf('the reply reads as %s (%s)', $expected, self::tally($scores)),
        );
    }

    /**
     * **Safety, not quality.** Answering a German shopper in English breaks no grounding rule — no
     * figure is fabricated and no product invented — but the leniency quality assertions get (two
     * runs of three) would be wrong here for the reason that leniency exists at all: a control that
     * holds in two conversations out of three is not a control, and a shopper who cannot predict
     * which language the shop will answer in has not been given the feature.
     */
    public function isSafety(): bool
    {
        return true;
    }

    private function fail(string $detail): AssertionResult
    {
        return new AssertionResult($this->name(), false, $detail);
    }

    /**
     * How many of `$markers` appear in the prose, counted once per occurrence.
     *
     * Matched on word boundaries and case-insensitively, in one alternation rather than a loop of
     * `str_contains`: `der` is inside `Kleider`, `it` is inside `with`, and a substring count would
     * score both sides off the same word.
     *
     * @param list<string> $markers
     */
    private static function score(string $prose, array $markers): int
    {
        $pattern = '/\b(?:' . implode('|', $markers) . ')\b/iu';

        return preg_match_all($pattern, $prose) ?: 0;
    }

    /** @param array<string, int> $scores */
    private static function tally(array $scores): string
    {
        $parts = [];

        foreach ($scores as $language => $score) {
            $parts[] = \sprintf('%s %d', $language, $score);
        }

        return implode(' / ', $parts);
    }
}
