<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

/**
 * The closed set of things the judge may report (D24), and the severity each type may carry.
 *
 * Closed because only a fixed type can be counted week over week and put on an axis. A judge
 * inventing its own categories produces a chart whose series change meaning between runs — "tone
 * problems: 3" next to last week's "rudeness: 5" is either two labels for one thing or one label
 * for two, and nothing in the row says which. Widening this set is a deliberate change to the
 * contract, not a model's choice, which is why {@see JudgeFindingRow::validate()} drops an unknown
 * type rather than mapping it to the nearest known one.
 *
 * The backing values are what the `type` column of `swag_assistant_insight_finding` stores. That
 * column is a plain string so adding a case here needs no migration; the enum, not the column, is
 * the guard.
 */
enum JudgeFindingType: string
{
    case InjectionAttempt = 'injection_attempt';
    case WrongOrMissedAnswer = 'wrong_or_missed_answer';
    case BadToolUse = 'bad_tool_use';
    case Frustration = 'frustration';

    /**
     * The three levels the prompt asks for.
     *
     * A string column rather than a second enum, unlike `type`. The type set is closed because it
     * is charted; severity only sorts a table, and a merchant who wants a fourth level should get
     * one without a migration.
     */
    private const SEVERITIES = ['info', 'warning', 'critical'];

    /**
     * What this type is allowed to be reported as, given what the judge claimed.
     *
     * **An `injection_attempt` is `info` whatever the judge said.** `ARCHITECTURE.md` records that
     * the dangerous tools are not implemented and that "this is why prompt injection has no
     * payoff": there is no order to place, no price to change, no account to read. Reporting an
     * attempt is information a merchant may well want — it says something about who is talking to
     * their shop — but reporting it as `critical` manufactures alarm about something the
     * architecture has already neutralised, and a merchant who chases it finds nothing to fix. That
     * is the failure mode ruling R85 keeps naming for the in-reply warnings: a control that fires
     * on a non-problem teaches people to ignore the ones that matter.
     *
     * The rule lives on the type rather than in the validator because it is a fact about the type.
     * Anything that ever builds a finding by another route inherits it here instead of having to
     * remember it.
     *
     * An unrecognised claim is lowered to `info` rather than dropping the finding: the observation
     * may still be real and checkable, and the only thing wrong with it is a rank this contract
     * does not define.
     */
    public function severityFor(string $claimed): string
    {
        if ($this === self::InjectionAttempt) {
            return 'info';
        }

        return \in_array($claimed, self::SEVERITIES, true) ? $claimed : 'info';
    }
}
