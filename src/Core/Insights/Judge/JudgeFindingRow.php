<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

/**
 * One row of the judge's JSON answer, checked against the request it was answering.
 *
 * Split out of {@see JudgeFindings} because the gate's per-class cyclomatic-complexity threshold of
 * 10 is a sum over the class and reading six fields off an untyped array costs most of it. It is the
 * better boundary regardless: {@see JudgeFindings} walks an answer and decides what a caller gets
 * back, this decides whether one claim is supported. Those are revised for different reasons — the
 * first when the transport changes, the second when a check is added.
 *
 * Three checks, and they run cheapest-first because that is also certainty-first. An unknown `type`
 * or an unknown `conversationId` is wrong about the *request*, and there is no point searching a
 * transcript for a quote attached to a conversation the judge was never shown.
 *
 * 1. An unknown `type` is dropped, never coerced (D24). A wrong label on a real chart is worse than
 *    a missing row: the missing row can be noticed, the wrong label cannot.
 * 2. A `conversationId` outside the sample is dropped. The judge saw no evidence about it, so a
 *    finding about it is invention by construction.
 * 3. **A `quote` that does not occur in the conversation it claims is dropped.** The only defence
 *    against a judge inventing its own evidence, and it costs one `str_contains`.
 *
 * Severity is not checked here — {@see JudgeFindingType::severityFor()} owns it, because "an
 * injection attempt is never above `info`" is a fact about the type rather than about this row.
 */
final class JudgeFindingRow
{
    private function __construct() {}

    /**
     * The finding this row stands for, or a refusal naming which check it failed.
     *
     * **The quote check is exact substring, case-insensitive, over the whole transcript
     * concatenated** — and the two directions of error are not symmetric, which is why it is not
     * fuzzy. A fuzzy match lets a paraphrase through, and a paraphrase is precisely the invention
     * being guarded against. An exact match loses a real finding whose quote the model retyped;
     * {@see JudgeRequest} warns the model about exactly that, so the loss is the model's to avoid
     * and costs a merchant nothing they could have checked anyway.
     *
     * Case-folded because a model that capitalises a sentence it copied from mid-sentence has not
     * invented anything. Concatenated rather than matched per turn because a quote may legitimately
     * read across a turn boundary, and refusing those would drop findings about the one thing only
     * a whole-conversation judge can see.
     *
     * @param array<array-key, mixed> $row
     * @param array<string, string>   $haystacks conversation id to its case-folded transcript, as
     *                                          {@see JudgeFindings::from()} builds it
     */
    public static function validate(array $row, array $haystacks): RowVerdict
    {
        $type = JudgeFindingType::tryFrom(self::text($row, 'type'));
        $conversationId = self::text($row, 'conversationId');
        $quote = self::text($row, 'quote');
        // Read out rather than tested with `isset` and indexed again: the analyzer does not carry
        // an `isset` on an array key through a compound condition, so the second read would be a
        // `possibly-null-argument` to `str_contains`. One lookup, one name, and the absence of the
        // conversation from the sample becomes a plain `null` the next line can read.
        $transcript = $haystacks[$conversationId] ?? null;

        $refusal = match (true) {
            $type === null => 'type outside the closed set',
            $transcript === null => 'conversation id not in the sample',
            $quote === '' => 'no quote given',
            !str_contains($transcript, mb_strtolower($quote)) => 'quote does not occur in that conversation',
            default => null,
        };

        if ($refusal !== null || $type === null) {
            return RowVerdict::refused($refusal ?? 'type outside the closed set');
        }

        return RowVerdict::accepted(new JudgeFinding(
            type: $type,
            severity: $type->severityFor(self::text($row, 'severity')),
            summary: self::text($row, 'summary'),
            quote: $quote,
            suggestion: self::text($row, 'suggestion'),
            conversationId: $conversationId,
        ));
    }

    /**
     * One field of a decoded row as a string, or `''` when it is absent or of any other type.
     *
     * A helper rather than a null-coalescing ternary at each of the six use sites, for two reasons.
     * The analyzer cannot narrow `$row['x'] ?? null` from `mixed` to `string` at the call site, so
     * this is where that narrowing happens once; and six inline ternaries put this class over the
     * complexity threshold on nothing but field reads, which would force a second split that says
     * nothing about the design.
     *
     * A missing field becomes `''` rather than dropping the row, because only `type`,
     * `conversationId` and `quote` decide whether a finding stands. A finding with a verified quote
     * and an empty `suggestion` is still evidence a merchant can act on; refusing it would throw
     * away a checked observation over a missing sentence.
     *
     * @param array<array-key, mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        /** @var mixed $value */
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
