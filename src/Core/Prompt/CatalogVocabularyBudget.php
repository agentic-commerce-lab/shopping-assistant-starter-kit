<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * Enforces {@see CatalogVocabulary}'s three bounds — at most 25 values per field, at
 * most 30 fields, and a 1500-character budget for the whole rendered block — and
 * assembles the final text. Split out of {@see CatalogVocabulary} purely to keep that
 * class's own aggregate cyclomatic complexity under this project's threshold, the same
 * reasoning documented on {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt} for
 * splitting out of {@see \Swag\AssistantStarterKit\Eval\JourneyRunner}.
 *
 * Values are trimmed before whole fields at every stage: the two hard caps apply
 * first, then, if the block still exceeds the character budget, {@see self::shrinkToBudget()}
 * lowers a single shared per-field value cap one step at a time — dropping a field
 * entirely only once its value cap reaches zero — rather than removing a field outright
 * while any other field still carries values.
 *
 * "Truncated" is decided the simplest way that is still exactly correct: the rendered
 * value count after every cut, compared against the value count before any cut. Any
 * difference between those two numbers IS a value or a whole field that did not make
 * it into the block, so no per-stage flag needs to be threaded through the pipeline.
 */
final class CatalogVocabularyBudget
{
    private const MAX_VALUES_PER_FIELD = 25;

    private const MAX_FIELDS = 30;

    private const MAX_TOTAL_CHARS = 1500;

    private function __construct() {}

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return array{text: string, fieldCount: int, valueCount: int, truncated: bool}
     */
    public static function fit(array $fields, string $heading, string $shortenedNote): array
    {
        $originalValueCount = self::totalValues($fields);

        $capped = self::capValuesAndFields($fields, self::MAX_VALUES_PER_FIELD, self::MAX_FIELDS);
        $final = self::shrinkToBudget($capped, $heading, $shortenedNote);

        $truncated = self::totalValues($final) < $originalValueCount;

        return [
            'text' => self::render($final, $heading, $truncated ? $shortenedNote : ''),
            'fieldCount' => \count($final),
            'valueCount' => self::totalValues($final),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     */
    private static function totalValues(array $fields): int
    {
        return array_sum(array_map(static fn(array $field): int => \count($field['values']), $fields));
    }

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return list<array{field: string, values: list<string>}>
     */
    private static function capValuesAndFields(array $fields, int $maxValues, int $maxFields): array
    {
        return self::withValueCap(\array_slice($fields, offset: 0, length: $maxFields), $maxValues);
    }

    /**
     * Lowers a single shared per-field value cap from {@see self::MAX_VALUES_PER_FIELD}
     * down to zero, one step at a time, returning as soon as the rendered block —
     * heading, lines, and the disclosure note this shrinking already requires — fits
     * the character budget.
     *
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return list<array{field: string, values: list<string>}>
     */
    private static function shrinkToBudget(array $fields, string $heading, string $shortenedNote): array
    {
        for ($cap = self::MAX_VALUES_PER_FIELD; $cap >= 0; --$cap) {
            $candidate = self::withValueCap($fields, $cap);

            if (\strlen(self::render($candidate, $heading, $shortenedNote)) <= self::MAX_TOTAL_CHARS) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return list<array{field: string, values: list<string>}>
     */
    private static function withValueCap(array $fields, int $cap): array
    {
        $limited = array_map(static fn(array $field): array => [
            'field' => $field['field'],
            'values' => \array_slice($field['values'], offset: 0, length: $cap),
        ], $fields);

        return array_values(array_filter($limited, static fn(array $field): bool => [] !== $field['values']));
    }

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     */
    private static function render(array $fields, string $heading, string $note): string
    {
        $lines = array_map(static fn(array $field): string => \sprintf(
            '%s: %s',
            $field['field'],
            implode(', ', $field['values']),
        ), $fields);

        $body = $heading . "\n\n" . implode("\n", $lines);

        return '' === $note ? $body : $body . "\n" . $note;
    }
}
