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
 * **A conversation's id is its position in the file.** The export carries no id at the top level,
 * and using the index consistently is what makes a finding checkable against the file by counting —
 * which is exactly what reading a precision figure by hand requires.
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
                id: (string) $index,
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
