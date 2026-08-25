<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;

/**
 * The three bounds on the vocabulary block — 25 values per field, 30 fields, 1500 characters — and
 * how the block degrades when a catalogue exceeds all of them at once.
 *
 * Split from {@see CatalogVocabularyTest}, which covers what renders and what is deliberately
 * skipped, along the same seam production uses: {@see CatalogVocabulary} decides what a facet set
 * means, {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabularyBudget} decides what fits.
 * Asserted through the public `CatalogVocabulary` surface rather than against the budget class
 * directly, because the bounds are a property of the block the model receives.
 */
final class CatalogVocabularyBudgetTest extends TestCase
{
    private const SHORTENED_NOTE = '(this list is shortened — a word missing from it does not mean the shop lacks it)';

    public function testCapsValuesAtTwentyFiveAndMarksTheListShortened(): void
    {
        $values = array_map(static fn(int $i): string => \sprintf('v%02d', $i), range(1, 40));
        $facets = new FacetSet([new Facet('Tag', FacetType::Terms, values: $values)]);

        $rendered = CatalogVocabulary::render($facets);

        self::assertMatchesRegularExpression('/^Tag: /m', $rendered);

        $line = self::fieldLine($rendered, 'Tag');
        $renderedValues = explode(', ', $line);

        self::assertCount(25, $renderedValues);
        self::assertSame(array_slice($values, 0, 25), $renderedValues);
        self::assertStringContainsString(self::SHORTENED_NOTE, $rendered);
    }

    public function testCapsFieldsAtThirtyAndMarksTheListShortened(): void
    {
        $facets = [];
        foreach (range(1, 40) as $i) {
            $facets[] = new Facet(\sprintf('field%02d', $i), FacetType::Terms, values: ['x']);
        }

        $rendered = CatalogVocabulary::render(new FacetSet($facets));

        /** @var array<int, list<string>> $matches */
        $matches = [];
        preg_match_all('/^field\d+: /m', $rendered, $matches);

        self::assertCount(30, $matches[0] ?? []);
        self::assertStringContainsString(self::SHORTENED_NOTE, $rendered);
    }

    public function testCutsToTheCharacterBudgetAndMarksTheListShortened(): void
    {
        // 30 fields x 25 long values each comfortably exceeds 1500 characters once the
        // heading is included — this is well past what the 25-values/30-fields caps
        // alone would allow through, so reaching the budget forces the additional
        // value-by-value cut this test exists to prove.
        $facets = [];
        foreach (range(1, 30) as $f) {
            $values = array_map(
                static fn(int $i): string => \sprintf('option-value-number-%02d-%02d', $f, $i),
                range(1, 25),
            );
            $facets[] = new Facet(\sprintf('field%02d', $f), FacetType::Terms, values: $values);
        }

        $rendered = CatalogVocabulary::render(new FacetSet($facets));

        // The load-bearing assertion: the actual rendered length against the stated
        // budget, not merely "shorter than some uncapped baseline" — a control that
        // silently stopped truncating would still be "shorter than the full render"
        // right up until it wasn't, so only the absolute bound proves anything.
        self::assertLessThanOrEqual(1500, \strlen($rendered));
        self::assertStringContainsString(self::SHORTENED_NOTE, $rendered);

        // And the assertion this test was missing, which is why it passed for as long as it
        // did while rendering nothing at all: a cut is only a cut if something survives it.
        // Measured before this line existed — this exact input rendered 0 fields and 0 values,
        // and both assertions above were satisfied by the heading and the note alone.
        self::assertMatchesRegularExpression('/^field\d\d: /m', $rendered);
    }

    /**
     * The cliff: a block that fits the budget only by containing no vocabulary at all.
     *
     * `shrinkToBudget()` lowered ONE shared per-field value cap from 25 toward 0 and returned the
     * first candidate under 1500 characters. At a cap of 0 every field has no values, and a field
     * with no values is dropped entirely — so the step below "one value each" was not "fewer values"
     * but "no vocabulary", and the block still carried the heading telling the model these are "the
     * only spellings this catalogue matches", followed by nothing.
     *
     * Measured against the generated 2,149-unit catalogue: 30 fields at one value each render 1,509
     * characters, nine over budget, so the real catalogue landed on the wrong side of it. 29 fields
     * render 1,476 and survive. One field decided between a usable vocabulary and none.
     *
     * @see docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md — Finding 1
     */
    public function testFallsBackToFewerFieldsRatherThanToNoVocabulary(): void
    {
        // Field names the length the DAL actually produces, and enough of them that one value each
        // still cannot fit — the exact shape that produced an empty block in production.
        $facets = [];
        foreach (range(1, 30) as $f) {
            $facets[] = new Facet(
                \sprintf('properties.Attribute %02d', $f),
                FacetType::Terms,
                values: array_map(static fn(int $i): string => \sprintf('value-%02d-%02d', $f, $i), range(1, 25)),
            );
        }

        $stats = CatalogVocabulary::renderWithStats(new FacetSet($facets));

        self::assertGreaterThan(0, $stats['fieldCount'], 'the block must never be empty when fields exist');
        self::assertGreaterThan(0, $stats['valueCount']);
        self::assertLessThanOrEqual(1500, \strlen($stats['text']));
        self::assertTrue($stats['truncated'], 'dropping fields is a truncation and must be disclosed');
    }

    /** Every field that survives the fallback must carry at least one value, or it says nothing. */
    public function testEveryFieldThatSurvivesTheBudgetCarriesAtLeastOneValue(): void
    {
        $facets = [];
        foreach (range(1, 30) as $f) {
            $facets[] = new Facet(
                \sprintf('properties.Attribute %02d', $f),
                FacetType::Terms,
                values: array_map(static fn(int $i): string => \sprintf('value-%02d-%02d', $f, $i), range(1, 25)),
            );
        }

        $rendered = CatalogVocabulary::render(new FacetSet($facets));

        $fieldLines = array_filter(explode("\n", $rendered), static fn(string $line): bool => str_starts_with(
            $line,
            'properties.',
        ));

        // Counted first, because the loop below asserts nothing when it runs zero times — and "zero
        // fields rendered" is the exact defect this file now guards against.
        self::assertNotEmpty($fieldLines);

        foreach ($fieldLines as $line) {
            self::assertMatchesRegularExpression('/^properties\.\S.*: \S/', $line);
        }
    }

    /**
     * The budget stays a hard bound. Dropping fields must not become a licence to overshoot 1500 —
     * a single pathological field wider than the whole budget still renders nothing.
     */
    public function testASingleFieldWiderThanTheWholeBudgetStillRendersEmpty(): void
    {
        $facets = new FacetSet([
            new Facet('properties.Enormous', FacetType::Terms, values: [str_repeat('x', 2000)]),
        ]);

        self::assertSame('', CatalogVocabulary::renderWithStats($facets)['text']);
    }

    private static function fieldLine(string $rendered, string $field): string
    {
        foreach (explode("\n", $rendered) as $line) {
            $prefix = $field . ': ';
            if (str_starts_with($line, $prefix)) {
                return substr($line, \strlen($prefix));
            }
        }

        self::fail(\sprintf('No line for field "%s" found in rendered vocabulary.', $field));
    }
}
