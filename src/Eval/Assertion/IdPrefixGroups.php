<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * Validates a journey's `groups` declaration into usable lists of id prefixes.
 *
 * Validating a declaration and judging a turn are different jobs, and keeping them apart is also what
 * puts each class under this project's complexity budget — {@see IdPrefixMatch} does the judging.
 *
 * **Prefixes rather than category paths, deliberately.** `ProductCard::categoryPath` is filled by the
 * fixture and left empty by the DAL, so an assertion reading it would pass in the eval suite and mean
 * nothing about production — the trap spec decision O1 exists to avoid. A trap product's id is
 * deterministic and gateway-independent.
 */
final class IdPrefixGroups
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $groups
     *
     * @return list<list<string>>|null null when the declaration itself is unusable
     */
    public static function parse(array $groups): ?array
    {
        if (\count($groups) < 2) {
            return null;
        }

        $parsed = [];

        foreach ($groups as $group) {
            $prefixes = self::prefixesIn($group);

            if ($prefixes === []) {
                return null;
            }

            $parsed[] = $prefixes;
        }

        return $parsed;
    }

    /**
     * Built by appending rather than with `array_filter`, so the analyzer sees non-empty strings
     * instead of inferring `mixed` through a callback.
     *
     * @return list<string>
     */
    private static function prefixesIn(mixed $group): array
    {
        $prefixes = [];

        foreach ((array) $group as $prefix) {
            if (\is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
