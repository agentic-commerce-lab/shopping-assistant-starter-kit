<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Trace\JsonShape;

/**
 * The `events` half of one exported conversation, normalised and sorted by `seq`.
 *
 * Validated through {@see JsonShape} rather than by hand. The first version of this class checked
 * `is_array` and `is_string` itself and was over the cyclomatic-complexity threshold while still
 * declaring a return type the analyzer could not prove — `json_decode` yields `array-key` keys and
 * the trace shape wants `string` ones. `JsonShape::map()` already casts keys and is what
 * {@see \Swag\AssistantStarterKit\Core\Trace\TranscriptCodec} reads stored JSON with, so this is one
 * reader for stored shapes rather than two.
 *
 * **Re-sorted by `seq` even though the export already sorts.** A corpus is for reproducing a bug,
 * and one hand-edited to isolate a turn may not be sorted any more — a conversation in which the
 * answer precedes the question is a different conversation.
 */
final readonly class ExportedEvents
{
    public function __construct(
        private JsonShape $shape = new JsonShape(),
    ) {}

    /**
     * @return list<array{seq:int,stage:string,payload:array<string,mixed>}>
     */
    public function of(mixed $raw): array
    {
        $events = [];

        foreach ($this->shape->map($raw) as $event) {
            $fields = $this->shape->map($event);
            $stage = $this->shape->textOrNull($fields['stage'] ?? null);

            if ($stage === null) {
                continue;
            }

            $seq = $fields['seq'] ?? null;

            $events[] = [
                'seq' => \is_int($seq) ? $seq : 0,
                'stage' => $stage,
                'payload' => $this->shape->map($fields['payload'] ?? null),
            ];
        }

        usort($events, static fn(array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return $events;
    }
}
