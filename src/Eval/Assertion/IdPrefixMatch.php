<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * Which prefix groups a rendered set of ids fails to represent.
 *
 * The judging half of {@see IdPrefixGroups}, split from it so neither class carries the nested loops
 * plus the validation branches — mago sums cyclomatic complexity across a class's methods.
 */
final class IdPrefixMatch
{
    private function __construct() {}

    /**
     * @param list<list<string>> $groups
     * @param list<string>       $ids
     *
     * @return list<string> one label per group nothing represents
     */
    public static function unrepresented(array $groups, array $ids): array
    {
        $missing = [];

        foreach ($groups as $prefixes) {
            if (!self::anyMatch($ids, $prefixes)) {
                $missing[] = implode('|', $prefixes);
            }
        }

        return $missing;
    }

    /**
     * One alternation per group rather than a nested loop: the same question asked in a single pass,
     * and it keeps this class's branch count low enough to sit beside the validator.
     *
     * @param list<string> $ids
     * @param list<string> $prefixes
     */
    private static function anyMatch(array $ids, array $prefixes): bool
    {
        $pattern = '/^(?:' . implode('|', array_map('preg_quote', $prefixes)) . ')/';

        foreach ($ids as $id) {
            if (preg_match($pattern, $id) === 1) {
                return true;
            }
        }

        return false;
    }
}
