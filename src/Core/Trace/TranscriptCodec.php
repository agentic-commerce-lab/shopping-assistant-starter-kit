<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Converts between {@see ConversationTurn} objects and the JSON stored in
 * `swag_assistant_conversation.transcript`.
 *
 * Decoding is where **everything is untrusted**, which is why this is its own class rather than two
 * private methods. The stored JSON was written by an earlier version of this plugin, possibly with a
 * different shape, and a decoder that assumes its own format is the only thing standing between a
 * schema change and a 500 on page load for every shopper still holding an old conversation token.
 *
 * So values are **narrowed, never cast** — a cast turns a wrong shape into a plausible-looking
 * value, which is the failure that gets believed — and a malformed entry is skipped rather than
 * decoded into a turn with empty everything, because an empty turn replayed into the widget reads as
 * "the assistant said nothing", which is a lie about the conversation.
 */
final readonly class TranscriptCodec
{
    public function __construct(
        private JsonShape $shape = new JsonShape(),
        private StoredTimestamp $timestamps = new StoredTimestamp(),
        private StoredWarnings $warnings = new StoredWarnings(),
    ) {}

    /**
     * @return array{
     *     role: string, prose: string, cardIds: list<string>, outcome: string,
     *     createdAt: string|null, warnings: array<string, list<string>>,
     * }
     */
    public function encode(ConversationTurn $turn): array
    {
        return [
            'role' => $turn->role,
            'prose' => $turn->prose,
            // Card IDS, not cards: a stored price is a fact frozen at write time and may be wrong by
            // the next page load. Every figure is re-rendered from the catalogue on read.
            'cardIds' => $turn->cardIds,
            'outcome' => $turn->outcome,
            // When the turn happened, not when it is read. A timestamp is the one thing here that is
            // *not* re-derived on read, because unlike a price it does not change.
            'createdAt' => $turn->createdAt?->format(\DATE_ATOM),
            // Stored so a reload does not restore the misleading sentence without its correction.
            'warnings' => $turn->warnings,
        ];
    }

    /**
     * @param array<int, mixed> $entries
     *
     * @return list<ConversationTurn>
     */
    public function decodeAll(array $entries): array
    {
        $turns = [];

        foreach ($entries as $entry) {
            $turn = $this->decode($entry);

            if ($turn !== null) {
                $turns[] = $turn;
            }
        }

        return $turns;
    }

    private function decode(mixed $entry): ?ConversationTurn
    {
        $fields = $this->shape->map($entry);

        $role = $this->shape->textOrNull($fields['role'] ?? null);
        $prose = $fields['prose'] ?? null;

        if ($role === null || !\is_string($prose)) {
            return null;
        }

        return new ConversationTurn(
            role: $role,
            prose: $prose,
            cardIds: $this->shape->strings($fields['cardIds'] ?? null),
            outcome: $this->shape->text($fields['outcome'] ?? null),
            createdAt: $this->timestamps->orNull($fields['createdAt'] ?? null),
            warnings: $this->warnings->fromStored($fields['warnings'] ?? null),
        );
    }
}
