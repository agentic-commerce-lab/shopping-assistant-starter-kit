<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFinding;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a replay's findings and a tally by type.
 *
 * Split from {@see JudgeReplayCommand} because the command was over the cyclomatic-complexity
 * threshold with the rendering inline — the same split the rest of this codebase already makes
 * between a command and its renderer ({@see ProbeRenderer}).
 *
 * **The quote is printed under each finding, indented, and never truncated.** Reading a precision
 * figure by hand means checking each quote against the conversation it claims, and a quote clipped
 * to fit a terminal is a quote that cannot be checked.
 */
final class JudgeReplayReport
{
    private function __construct() {}

    /** @param list<JudgeFinding> $findings */
    public static function render(SymfonyStyle $io, array $findings): int
    {
        if ($findings === []) {
            $io->success('The judge reported nothing. That is not the same as nothing being wrong.');

            return 0;
        }

        $tally = [];

        foreach ($findings as $finding) {
            $io->writeln(\sprintf(
                '%s | %s | conversation %s | %s',
                $finding->type->value,
                $finding->severity,
                $finding->conversationId,
                $finding->summary,
            ));
            $io->writeln('    "' . $finding->quote . '"');
            $io->writeln('    -> ' . $finding->suggestion);
            $io->newLine();

            $tally[$finding->type->value] = ($tally[$finding->type->value] ?? 0) + 1;
        }

        $io->writeln(\sprintf('%d findings: %s', \count($findings), json_encode($tally, \JSON_THROW_ON_ERROR)));
        $io->note('Read every one against its conversation before quoting a precision figure (R85).');

        return 0;
    }
}
