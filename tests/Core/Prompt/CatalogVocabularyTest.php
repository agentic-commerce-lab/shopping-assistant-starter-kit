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

    public function testSafetyHeadingIsPresentWheneverAnyLineRenders(): void
    {
        $facets = new FacetSet([new Facet('Size', FacetType::Terms, values: ['M'])]);

        $rendered = CatalogVocabulary::render($facets);

        self::assertStringContainsString('Words this shop uses.', $rendered);
        self::assertStringContainsString('search vocabulary, not an inventory', $rendered);
        self::assertStringContainsString('always call a tool and let the shop answer.', $rendered);
    }
}
