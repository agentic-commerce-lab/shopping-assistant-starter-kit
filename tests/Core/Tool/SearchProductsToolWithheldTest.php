<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What the reply says about its own limits.
 *
 * Split out by concern, following `SearchProductsToolLimitTest` and
 * `SearchProductsToolNarrowingTest`. The concern here: phase B found the reply carries
 * `total => count($returned)`, so 500 matching products were reported as 5 with nothing saying
 * otherwise — a bounded answer that reads as a complete one.
 *
 * @see docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md
 */
final class SearchProductsToolWithheldTest extends TestCase
{
    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }

    public function testMatchedReportsWhatSurvivedRetrievalNotWhatWasReturned(): void
    {
        // Gravel Tyre 40c has four variants in the fixture; asking for two must still report four.
        $result = $this->tool()(term: 'Gravel Tyre', limit: 2);

        self::assertSame(2, $result['total'], 'total keeps meaning the length of products (T3)');
        self::assertCount(2, $result['products']);
        self::assertSame(4, $result['matched']);
    }

    public function testAnUntruncatedSearchReportsMatchedEqualToTotal(): void
    {
        $result = $this->tool()(term: 'Gravel Tyre', limit: 8);

        self::assertSame($result['total'], $result['matched']);
        self::assertFalse($result['more'], 'the window was not saturated, so this is the whole answer');
    }

    /**
     * `more` is the honest half of `matched`: when the candidate window filled up, `matched` is a
     * floor rather than a count (spec decision T4).
     *
     * At `limit: 1` the candidate window is `MIN_CANDIDATES` (20), and the fixture holds 17 sellable
     * units — so it cannot saturate, and `more` must be false rather than defaulting to true.
     */
    public function testMoreIsFalseWhenTheCandidateWindowWasNotFilled(): void
    {
        self::assertFalse($this->tool()(term: 'bottle', limit: 1)['more']);
    }

    public function testAMissedSearchStillCarriesBothFields(): void
    {
        $result = $this->tool()(term: 'zzzznotathing');

        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['matched']);
        self::assertFalse($result['more']);
        self::assertArrayHasKey('note', $result);
    }

    /**
     * The failure this whole change exists for, in miniature.
     *
     * Two of four Gravel Tyre variants come back. `Tan` and `650x47` are only on the two that did
     * not — and the model must still be able to ask for them.
     */
    public function testATruncatedFamilyDisclosesTheOptionsOfTheVariantsItWithheld(): void
    {
        $result = $this->tool()(term: 'Gravel Tyre', limit: 2);

        self::assertArrayHasKey('families', $result);

        // `families` is optional in the declared return shape, so it is coalesced before use rather
        // than narrowed by assertArrayHasKey — PHPUnit's assertions are not type guards.
        $families = $result['families'] ?? [];
        self::assertCount(1, $families);

        $family = $families[0] ?? self::fail('no family summary');
        self::assertSame('Gravel Tyre 40c', $family['name']);
        self::assertSame(2, $family['shown']);
        self::assertSame(4, $family['variants']);
        self::assertContains('Tan', $family['options']['Colour'] ?? []);
        self::assertContains('650x47', $family['options']['Size'] ?? []);
    }

    /** No truncation, no key. An empty families array is noise the model pays tokens to read. */
    public function testAnUntruncatedSearchOmitsTheFamiliesKeyEntirely(): void
    {
        self::assertArrayNotHasKey('families', $this->tool()(term: 'Gravel Tyre', limit: 8));
    }

    /**
     * Spec decision T6 at the level that ships: whatever `families` contains, the reply the model
     * receives carries no figure. Asserted on the encoded reply because that is what crosses the
     * boundary.
     */
    public function testTheRepliesNewFieldsNeverCarryAFigure(): void
    {
        $result = $this->tool()(term: 'Gravel Tyre', limit: 2);
        unset($result['products'], $result['note']);

        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'deliveryTime', 'url', 'EUR'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, \sprintf('%s leaked', $forbidden));
        }
    }
}
