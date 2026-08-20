<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Narrows untrusted JSON values into typed ones.
 *
 * Shared by {@see TranscriptCodec} and {@see DalConversationStore} so the same rules apply to a
 * stored transcript and to a stored trace payload.
 *
 * "Untrusted" is literal rather than defensive habit: both were written by an earlier version of this
 * plugin, possibly with a different shape. Values are narrowed, never cast — a cast turns a wrong
 * shape into a plausible-looking value, and a plausible-looking value is the one that gets believed.
 */
final readonly class JsonShape
{
    public function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    public function textOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function strings(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
