<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Trace\JsonShape;

/**
 * A night's conversations read out of a trace export file instead of the database.
 *
 * This is what makes decision D28 possible. The judge's accuracy cannot have a unit test — its
 * output is a judgement — so the substitute is a replay over a corpus exported once and archived,
 * with every finding read by hand. That replay has to see exactly what the nightly run sees, and
 * the only way to guarantee it is to share the aggregator and the judge and vary only the source.
 *
 * Reads {@see \Swag\AssistantStarterKit\Core\Trace\Export\TraceJsonSerialiser}'s shape: a list of
 * `{summary, transcript, events}`.
 *
 * **A conversation's id is its position in the file, prefixed.** The export carries no id at the top
 * level, and the position is what makes a finding checkable against the file by counting — exactly
 * what reading a precision figure by hand requires.
 *
 * **The prefix is not cosmetic.** A bare `"17"` is a JSON identifier a model will return as the
 * NUMBER 17, and `JudgeFindingRow` reads a non-string field as absent, so the finding is refused as
 * "conversation id not in the sample". Measured on 2026-09-16: a replay over 33 conversations
 * returned three findings and lost all three that way, while reporting "the judge reported nothing".
 * Real conversations carry 32-character hex ids and never hit this; the replay invented the problem
 * by numbering its own, and `c17` removes it at the source rather than by loosening the validator.
 *
 * **`inWindow()` ignores its window.** A file IS the window: it holds what somebody chose to
 * export, and filtering it again by a date range would silently narrow the fixed thing two runs are
 * being compared over.
 */
final readonly class ExportTraceSource implements ConversationTraceSource
{
    /** @param list<ConversationTrace> $traces */
    private function __construct(
        private array $traces,
    ) {}

    /**
     * @throws \JsonException    when the file is not a JSON list of conversations
     * @throws \RuntimeException when the file cannot be read
     */
    public static function fromFile(string $path, JsonShape $shape = new JsonShape()): self
    {
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('The export at "%s" is not readable.', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \JsonException('A trace export is a list of conversations; this file is not.');
        }

        $events = new ExportedEvents($shape);
        $transcripts = new ExportedTranscript($shape);
        $traces = [];

        foreach (array_values($decoded) as $index => $conversation) {
            $fields = $shape->map($conversation);

            $traces[] = new ConversationTrace(
                id: 'c' . $index,
                createdAt: new \DateTimeImmutable(),
                events: $events->of($fields['events'] ?? null),
                transcript: $transcripts->of($fields['transcript'] ?? null),
            );
        }

        return new self($traces);
    }

    /** @return list<ConversationTrace> */
    public function inWindow(InsightWindow $window): array
    {
        return $this->traces;
    }
}
