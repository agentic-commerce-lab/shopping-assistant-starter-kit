<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Renders a {@see TraceRecorder} as text a human can read in a terminal.
 *
 * This exists because of a gap the handoff named outright: **no trace on this branch had ever been
 * read end to end.** Every claim about model behaviour was inferred from which assertion failed.
 * `TraceRecorder` collected everything and nothing consumed it.
 *
 * **Built on `events()`, never `stages()`.** `stages()` de-duplicates by design (ruling R18), so a
 * dumper using it would collapse a second tool round out of existence — and exhausting the
 * tool-call budget was a live pilot blocker (ruling R52) that is invisible without seeing both
 * calls. Per-value rules live in {@see TraceValueRenderer}.
 */
final readonly class TraceDumper
{
    public function __construct(
        private TraceValueRenderer $values = new TraceValueRenderer(),
    ) {}

    public function dump(TraceRecorder $trace): string
    {
        $events = $trace->events();

        if ($events === []) {
            // A turn that recorded no stage is a finding, not an empty page. Blank output would
            // read as "the dump failed" and send the reader looking in the wrong place.
            return "trace: no events recorded — the pipeline did not run.\n";
        }

        $lines = [\sprintf('trace: %d events', \count($events)), ''];

        foreach ($events as $event) {
            $lines[] = \sprintf('#%d %s', $event->seq, $event->stage);

            foreach ($this->payloadLines($event) as $line) {
                $lines[] = '    ' . $line;
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function payloadLines(TraceEvent $event): array
    {
        if ($event->payload === []) {
            return ['(no payload)'];
        }

        $lines = [];

        foreach ($event->payload as $key => $value) {
            $lines[] = \sprintf('%s: %s', $key, $this->values->render((string) $key, $value));
        }

        return $lines;
    }
}
