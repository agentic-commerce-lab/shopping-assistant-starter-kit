<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Reads a turn's grounding warnings out of stored JSON.
 *
 * Its own class for the reason the quality gate gave: folded into {@see JsonShape} it pushed that
 * class past the complexity threshold, and folded into {@see TranscriptCodec} it pushed *that* one
 * past it. Both were the gate correctly reporting that a class had grown a second job, so this is
 * the third small collaborator in the same family — shapes, dates, warnings — rather than a
 * suppression.
 *
 * Keys are kept as stored rather than validated against a known list. The producer is
 * `AssistantController`, and a warning kind added there must survive a read written before it
 * existed; an allowlist here would silently drop it.
 */
final readonly class StoredWarnings
{
    public function __construct(
        private JsonShape $shape = new JsonShape(),
    ) {}

    /**
     * Drops any key whose value narrows to nothing.
     *
     * An empty list carries no information, and keeping `{"unbackedPrices": []}` would push an
     * empty-versus-absent distinction onto every reader for no gain.
     *
     * @return array<string, list<string>>
     */
    public function fromStored(mixed $value): array
    {
        $warnings = [];

        foreach ($this->shape->map($value) as $key => $entry) {
            $strings = $this->shape->strings($entry);

            if ($strings !== []) {
                $warnings[$key] = $strings;
            }
        }

        return $warnings;
    }
}
