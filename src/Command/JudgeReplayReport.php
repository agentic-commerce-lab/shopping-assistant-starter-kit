<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Insights\Judge\ValidatedFindings;
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
 *
 * **The discarded count is printed even when it is zero**, because its absence is what made the
 * first real replay uninterpretable: 33 conversations, no findings, and no way to tell a model that
 * answered `[]` from one whose every finding failed the quote check.
 */
final class JudgeReplayReport
{
    private function __construct() {}

    public static function render(SymfonyStyle $io, ValidatedFindings $validated): int
    {
        if ($validated->discardReasons !== []) {
            $io->writeln('Discarded: ' . self::reasons($validated->discardReasons));
        }

        $io->writeln(\sprintf(
            '%d findings survived validation, %d rows were discarded.',
            \count($validated->findings),
            $validated->discarded,
        ));

        if ($validated->findings === [] && $validated->discarded === 0) {
            $io->success('The judge answered with an empty list. That is not the same as nothing being wrong.');

            return 0;
        }

        if ($validated->findings === []) {
            $io->warning(\sprintf(
                "Every one of the %d rows the judge returned was discarded:\n%s\n\nThis is a broken "
                . 'control, not a quiet night.',
                $validated->discarded,
                self::reasons($validated->discardReasons),
            ));

            return 0;
        }

        $tally = [];

        foreach ($validated->findings as $finding) {
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

        $io->writeln(\sprintf(
            '%d findings: %s',
            \count($validated->findings),
            json_encode($tally, \JSON_THROW_ON_ERROR),
        ));
        $io->note('Read every one against its conversation before quoting a precision figure (R85).');

        return 0;
    }

    /**
     * The refusal tally as lines a person can act on.
     *
     * Named reasons rather than a count, because the three are three different fixes: a model that
     * paraphrases its quotes, one that returns the conversation id as a number, and one that invents
     * types are not the same problem and none of them is guessable from "5 discarded".
     *
     * @param array<string, int> $reasons
     */
    private static function reasons(array $reasons): string
    {
        $lines = [];

        foreach ($reasons as $reason => $count) {
            $lines[] = \sprintf('  %d x %s', $count, $reason);
        }

        return implode("\n", $lines);
    }
}
