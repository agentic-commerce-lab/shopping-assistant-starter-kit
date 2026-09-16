<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Trace\JsonShape;

/**
 * The `transcript` half of one exported conversation.
 *
 * Deliberately not {@see \Swag\AssistantStarterKit\Core\Trace\TranscriptCodec}, which decodes into
 * `ConversationTurn` objects carrying card ids and timestamps and refuses an entry it cannot fully
 * decode. A corpus is often hand-trimmed to the two fields a judge reads, and dropping those turns
 * would quietly shrink the very thing a precision figure is measured over. Two readers, with that
 * difference stated, beats one reader that is wrong for one of the two jobs.
 */
final readonly class ExportedTranscript
{
    public function __construct(
        private JsonShape $shape = new JsonShape(),
    ) {}

    /**
     * @return list<array{role:string,prose:string}>
     */
    public function of(mixed $raw): array
    {
        $turns = [];

        foreach ($this->shape->map($raw) as $turn) {
            $fields = $this->shape->map($turn);
            $prose = $this->shape->textOrNull($fields['prose'] ?? null);

            if ($prose === null) {
                continue;
            }

            $turns[] = [
                'role' => $this->shape->text($fields['role'] ?? null),
                'prose' => $prose,
            ];
        }

        return $turns;
    }
}
