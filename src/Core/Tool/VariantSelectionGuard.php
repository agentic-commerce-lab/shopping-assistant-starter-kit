<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Parses and bounds a tool's raw "options" argument into
 * {@see VariantSelection} objects. Split out of {@see Guard} because keeping
 * this alongside the scalar bound checks pushed that class's aggregate
 * cyclomatic complexity over the project's configured threshold.
 */
final class VariantSelectionGuard
{
    /**
     * @param array<int, mixed>|null $raw
     *
     * @return list<VariantSelection>
     */
    public static function fromRaw(?array $raw, string $name): array
    {
        $selections = [];
        foreach (Guard::boundedArray($raw, 10, $name) ?? [] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['option'] ?? null)) {
                throw new ToolArgumentException(sprintf('Argument "%s" entries need an "option" string.', $name));
            }

            $group = $entry['group'] ?? null;
            $selections[] = new VariantSelection(
                Guard::boundedString($entry['option'], 120, $name . '.option') ?? '',
                \is_string($group) ? Guard::boundedString($group, 120, $name . '.group') : null,
            );
        }

        return $selections;
    }
}
