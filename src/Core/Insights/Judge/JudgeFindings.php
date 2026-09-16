<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Turns the model's answer into findings, dropping everything it cannot stand behind.
 *
 * The single way a {@see JudgeFinding} comes into existence, which is what makes the checks in
 * {@see JudgeFindingRow::validate()} mandatory rather than advisory. Nothing downstream re-verifies
 * a finding it is handed, so a second construction path would silently make the validator optional
 * — and the validator is the whole reason a merchant can trust a row enough to act on it.
 *
 * This class owns the *answer*: decoding it, folding each sampled conversation into one searchable
 * transcript, and collecting what survives. One row's worth of judgement lives in
 * {@see JudgeFindingRow}, which the gate's per-class complexity budget forced apart and which reads
 * better for it.
 *
 * **Malformed JSON throws rather than yielding nothing.** The caller has to be able to tell "the
 * judge found no problems" from "the judge did not answer": in a dashboard both are the same empty
 * table and they mean opposite things. The run row's `judge_error` column exists for the second
 * case and can only be filled if this raises.
 */
final class JudgeFindings
{
    private function __construct() {}

    /**
     * @param list<ConversationTrace> $traces the sample the judge was actually shown; a finding
     *                                       about any other conversation is dropped
     *
     * @throws \JsonException when the model's answer is not a JSON array — propagated deliberately,
     *                        so the run can record *why* it has no findings
     *
     * @return list<JudgeFinding>
     */
    public static function from(string $json, array $traces): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \JsonException('The judge answered with something that is not a list of findings.');
        }

        // One case-folded haystack per conversation, built once before the rows are walked: a judge
        // reporting eight findings about one conversation would otherwise refold the same
        // transcript eight times, and the sample is whole conversations.
        $haystacks = [];

        foreach ($traces as $trace) {
            $haystacks[$trace->id] = mb_strtolower(implode(' ', array_column($trace->transcript, 'prose')));
        }

        $findings = [];

        foreach ($decoded as $row) {
            // A row that is not even an object is passed on as an empty one rather than skipped
            // here, so that every reason a row is dropped lives in one place.
            $finding = JudgeFindingRow::validate(\is_array($row) ? $row : [], $haystacks);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
