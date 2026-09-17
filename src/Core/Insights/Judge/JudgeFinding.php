<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

/**
 * One thing the judge says went wrong in one conversation, after validation.
 *
 * **Only {@see JudgeFindings::from()} may build these**, and that is the whole reason this class is
 * so thin. A `JudgeFinding` in hand has already had its type checked against the closed set, its
 * conversation checked against the sample, and — the one that matters — its `quote` checked to
 * occur in the transcript it claims. Nothing downstream re-verifies any of that, so the value of
 * this type is precisely that it cannot be reached without passing {@see JudgeFindingRow}.
 *
 * `severity` is a string rather than an enum, unlike `type`. The type set is closed because it is
 * charted (D24); severity is not charted, it only sorts a table, and a merchant who wants a fourth
 * level should get one without a migration. {@see JudgeFindingType::severityFor()} still narrows it
 * to the three the prompt asks for, because an unknown value sorts arbitrarily.
 *
 * `conversationId` is required here even though the database column is nullable. The link is
 * verified at construction — a finding cannot exist without one — and is only lost later, when the
 * pruner deletes a conversation out from under a finding row that outlived it.
 */
final readonly class JudgeFinding
{
    // @mago-expect lint:excessive-parameter-list
    // One parameter per column of `swag_assistant_insight_finding`, minus the run id the writer
    // supplies. Grouping the three prose fields behind a sub-object would pass the rule and make
    // every reader of a finding write `$finding->text->quote`, which is indirection bought with the
    // readability of the one class a merchant's evidence passes through. Same reasoning as
    // {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary}: no natural sub-object exists.
    public function __construct(
        public JudgeFindingType $type,
        public string $severity,
        public string $summary,
        public string $quote,
        public string $suggestion,
        public string $conversationId,
    ) {}
}
