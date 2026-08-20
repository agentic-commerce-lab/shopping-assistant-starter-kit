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
     * Entry shapes are normalised by {@see VariantSelectionShape} before the bounds
     * below apply, because the shape the tool schema documents is not the shape a live
     * model sends — see that class for the measurement and for why widening it is not
     * the coercion {@see Guard} refuses to do.
     *
     * @param array<array-key, mixed>|null $raw
     *
     * @return list<VariantSelection>
     */
    public static function fromRaw(?array $raw, string $name): array
    {
        $selections = [];
        foreach (Guard::boundedArray($raw, 10, $name) ?? [] as $key => $entry) {
            $normalised = VariantSelectionShape::normalise($key, $entry);
            if ($normalised === null) {
                throw new ToolArgumentException(sprintf(
                    'Argument "%s" entries need an "option" string, a "group": "option" pair, or a bare '
                    . 'option value.',
                    $name,
                ));
            }

            $selections[] = new VariantSelection(
                Guard::boundedString($normalised['option'], 120, $name . '.option') ?? '',
                $normalised['group'] !== null
                    ? Guard::boundedString($normalised['group'], 120, $name . '.group')
                    : null,
            );
        }

        return $selections;
    }
}
