<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Reads a two-element `["Colour", "Black"]` tuple as a group/option selection.
 *
 * Its own class rather than a third method on {@see VariantSelectionShape} because
 * mago sums cyclomatic complexity per class and that one is already at its budget —
 * the same split-rather-than-suppress move that produced
 * {@see VariantSelectionGuard} out of {@see Guard}.
 */
final class VariantSelectionPair
{
    private function __construct() {}

    /**
     * The pair is read as `[group, option]`: the order the measured model used, and the
     * same order the `{"Colour":"Black"}` map shape puts them in.
     *
     * A pair sent the other way round has its group resolved against the catalogue's
     * own facets and is dropped when no facet matches — exactly what already happens
     * for any unrecognised group name. A dropped filter widens the result set rather
     * than narrowing it to the wrong member, so the failure mode is a wasted filter,
     * never a wrong variant.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array{option: string, group: ?string}|null null when the entry is not a
     *                                                    pair of two strings, which is
     *                                                    ambiguous rather than readable
     */
    public static function read(array $entry): ?array
    {
        if (\count($entry) !== 2 || !\array_is_list($entry)) {
            return null;
        }

        [$group, $option] = $entry;

        return \is_string($group) && \is_string($option) ? ['option' => $option, 'group' => $group] : null;
    }
}
