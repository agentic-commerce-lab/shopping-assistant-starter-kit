<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * The question sentences in a reply, with continuations folded into what they continue.
 *
 * Split from {@see QuestionsAtMost} because mago sums cyclomatic complexity across a class's methods,
 * and the counting rule is the complicated half while the assertion is only a comparison. The split is
 * the better boundary too: *what counts as one question* is a judgement worth naming and testing on its
 * own.
 *
 * **Sentences, not question marks.** *"Menswear or womenswear? Or both?"* is one thing asked. Counting
 * marks would call it two and fail a turn that behaved correctly — and an assertion that fires on
 * correct behaviour trains people to ignore it, which this project has already paid for once (R85).
 *
 * A sentence opening with a coordinating conjunction (`or`, `and`) continues the one before it. That is
 * a judgement, not a fact about English, and it is stated here so a reader does not take it for a bug.
 */
final class ProseQuestions
{
    private function __construct() {}

    /** @return list<string> */
    public static function in(string $prose): array
    {
        $matches = [];
        preg_match_all('/[^.!?\n]*\?/u', $prose, $matches);

        $questions = [];

        foreach ($matches[0] ?? [] as $raw) {
            $sentence = trim((string) $raw);

            if ($sentence === '' || self::continues($sentence, $questions)) {
                continue;
            }

            $questions[] = $sentence;
        }

        return $questions;
    }

    /** @param list<string> $sofar */
    private static function continues(string $sentence, array $sofar): bool
    {
        return $sofar !== [] && preg_match('/^(?:or|and)\b/i', $sentence) === 1;
    }
}
