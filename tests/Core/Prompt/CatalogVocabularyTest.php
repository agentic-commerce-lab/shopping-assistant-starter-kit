<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;

final class CatalogVocabularyTest extends TestCase
{
    private const SHORTENED_NOTE = '(this list is shortened — a word missing from it does not mean the shop lacks it)';

    public function testRendersATermsFacetAsFieldColonValues(): void
    {
        $facets = new FacetSet([new Facet('Size', FacetType::Terms, values: ['M', 'L'])]);

        self::assertStringContainsString('Size: M, L', CatalogVocabulary::render($facets));
    }

    public function testSkipsARangeFacetEntirely(): void
    {
        $facets = new FacetSet([
            new Facet('price', FacetType::Range, min: 1.0, max: 100.0),
            new Facet('Size', FacetType::Terms, values: ['M']),
        ]);

        $rendered = CatalogVocabulary::render($facets);

        self::assertStringContainsString('Size: M', $rendered);
        self::assertStringNotContainsString('price', $rendered);
        self::assertStringNotContainsString('100', $rendered);
    }

    public function testAllRangeFacetSetRendersEmpty(): void
    {
        $facets = new FacetSet([new Facet('price', FacetType::Range, min: 1.0, max: 100.0)]);

        self::assertSame('', CatalogVocabulary::render($facets));
    }

    public function testEmptyFacetSetRendersEmpty(): void
    {
        self::assertSame('', CatalogVocabulary::render(new FacetSet([])));
    }

    public function testSkipsAFacetWithNoValues(): void
    {
        $facets = new FacetSet([new Facet('Colour', FacetType::Terms, values: [])]);

        self::assertSame('', CatalogVocabulary::render($facets));
    }

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
    }

    public function testSafetyHeadingIsPresentWheneverAnyLineRenders(): void
    {
        $facets = new FacetSet([new Facet('Size', FacetType::Terms, values: ['M'])]);

        $rendered = CatalogVocabulary::render($facets);

        self::assertStringContainsString('Words this shop uses.', $rendered);
        self::assertStringContainsString('search vocabulary, not an inventory', $rendered);
        self::assertStringContainsString('always call a tool and let the shop answer.', $rendered);
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
