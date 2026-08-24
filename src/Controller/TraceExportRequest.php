<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * A validated export request.
 *
 * Split out of {@see AssistantTraceExportController} for the reason {@see ChatRequest} was split out
 * of {@see AssistantController} — cyclomatic complexity — and the seam is the right one anyway:
 * parsing a request body is its own concern with its own rules, and the controller is left with the
 * policy it exists to enforce.
 *
 * **The endpoint is authenticated, and the body is still client input.** A malformed id has no
 * business reaching a repository lookup; that rule does not soften because the caller had to log in
 * first.
 */
final readonly class TraceExportRequest
{
    /**
     * @param list<string> $ids    well-formed catalogue ids only; anything else was dropped
     * @param ?string      $format `csv`, `json`, or null when the request named neither
     */
    private function __construct(
        public array $ids,
        public ?string $format,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $decoded = json_decode((string) $request->getContent(), associative: true);
        $payload = \is_array($decoded) ? $decoded : [];

        $format = $payload['format'] ?? null;

        return new self(
            ids: self::ids($payload['ids'] ?? null),
            // Null rather than a default: handing a merchant who asked for JSON a CSV without
            // saying so is worse than refusing, so the controller must be able to tell "asked for
            // something impossible" from "asked for csv".
            format: $format === 'csv' || $format === 'json' ? $format : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $id) {
            if (\is_string($id) && preg_match(CardIdList::ID_PATTERN, $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
