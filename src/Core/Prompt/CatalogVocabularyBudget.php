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

    /**
     * The lowest shared per-field value cap worth trying.
     *
     * **One, not zero, and this is the whole fix.** A field with no values is dropped entirely by
     * {@see self::withValueCap()}, so a cap of zero does not mean "fewer values" — it means no
     * vocabulary, and the loop below used to walk straight into it. Measured against a 2,149-unit
     * catalogue: 30 fields at one value each render 1,509 characters against this budget of 1,500,
     * nine over, so a real catalogue landed on the wrong side and the model was handed a heading
     * promising "the only spellings this catalogue matches" followed by nothing at all.
     *
     * Below this cap the block degrades by dropping whole FIELDS instead — which is what this
     * class's own contract said all along ("values before whole fields at every stage"), and what
     * {@see self::dropFieldsToBudget()} now does.
     *
     * @see docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md — Finding 1
     */
    private const MIN_VALUES_PER_FIELD = 1;

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
     * Lowers a single shared per-field value cap from {@see self::MAX_VALUES_PER_FIELD} down to
     * {@see self::MIN_VALUES_PER_FIELD}, one step at a time, returning as soon as the rendered
     * block — heading, lines, and the disclosure note this shrinking already requires — fits the
     * character budget.
     *
     * If even one value per field does not fit, the cap stops shrinking and whole fields start
     * dropping instead: a block naming twenty groups with one value each tells the model something,
     * and a block naming none tells it something false.
     *
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return list<array{field: string, values: list<string>}>
     */
    private static function shrinkToBudget(array $fields, string $heading, string $shortenedNote): array
    {
        for ($cap = self::MAX_VALUES_PER_FIELD; $cap >= self::MIN_VALUES_PER_FIELD; --$cap) {
            $candidate = self::withValueCap($fields, $cap);

            if (self::fits($candidate, $heading, $shortenedNote)) {
                return $candidate;
            }
        }

        return self::dropFieldsToBudget(
            self::withValueCap($fields, self::MIN_VALUES_PER_FIELD),
            $heading,
            $shortenedNote,
        );
    }

    /**
     * Drops trailing fields, one at a time, until the block fits — the last resort below one value
     * per field.
     *
     * Trailing, because {@see self::capValuesAndFields()} already keeps the first
     * {@see self::MAX_FIELDS} in the order the gateway's aggregation returned them, and this stays
     * consistent with that rather than inventing a second, different notion of which fields matter
     * most. Whether that order is the *right* one is a separate question this class cannot answer —
     * it never sees anything but the facets handed to it.
     *
     * Returns an empty list only when a single field cannot fit, which needs one field name plus one
     * value to exceed roughly 900 characters. The budget stays a hard bound: this method never
     * overshoots it to keep a field.
     *
     * @param list<array{field: string, values: list<string>}> $fields
     *
     * @return list<array{field: string, values: list<string>}>
     */
    private static function dropFieldsToBudget(array $fields, string $heading, string $note): array
    {
        for ($count = \count($fields); $count >= 1; --$count) {
            $candidate = \array_slice($fields, offset: 0, length: $count);

            if (self::fits($candidate, $heading, $note)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param list<array{field: string, values: list<string>}> $fields
     */
    private static function fits(array $fields, string $heading, string $note): bool
    {
        return \strlen(self::render($fields, $heading, $note)) <= self::MAX_TOTAL_CHARS;
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
        // No fields means no block at all — not a heading with nothing under it. The heading tells
        // the model these are "the only spellings this catalogue matches"; shipping it above an
        // empty list is worse than shipping neither, because it invites the model to read absence
        // from the list as absence from the shop. This is the same '' that
        // {@see CatalogVocabulary::renderWithStats()} already returns for a facet set with no terms
        // facets, so the two ways of having nothing to say now look identical downstream.
        if ([] === $fields) {
            return '';
        }

        $lines = array_map(static fn(array $field): string => \sprintf(
            '%s: %s',
            $field['field'],
            implode(', ', $field['values']),
        ), $fields);

        $body = $heading . "\n\n" . implode("\n", $lines);

        return '' === $note ? $body : $body . "\n" . $note;
    }
}
