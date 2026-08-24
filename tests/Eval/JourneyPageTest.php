<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\JourneyPage;

final class JourneyPageTest extends TestCase
{
    public function testAJourneyWithNoPageBlockIsOnNoPage(): void
    {
        // The twelve journeys written before page context existed must keep parsing unchanged.
        $page = JourneyPage::parse(null, 'some_journey');

        self::assertNull($page->productId);
        self::assertNull($page->categoryId);
    }

    public function testItCarriesTheTwoIdsTheStorefrontSends(): void
    {
        $page = JourneyPage::parse(['productId' => 'fx-026-blue-m', 'categoryId' => 'Jerseys'], 'some_journey');

        self::assertSame('fx-026-blue-m', $page->productId);
        self::assertSame('Jerseys', $page->categoryId);
    }

    public function testEitherIdMayStandAlone(): void
    {
        $page = JourneyPage::parse(['categoryId' => 'Jerseys'], 'some_journey');

        self::assertSame('Jerseys', $page->categoryId);
        self::assertNull($page->productId);
    }

    /**
     * The rule JourneyConfig already applies to its own block, for the same reason: a key nothing
     * reads makes the journey a claim about a page it was not run on.
     */
    public function testAnUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/searchTerm/');

        JourneyPage::parse(['searchTerm' => 'jersey'], 'some_journey');
    }

    public function testAnUnusableValueIsRefused(): void
    {
        foreach ([['productId' => ''], ['productId' => 42], ['categoryId' => []], 'not-an-array'] as $bad) {
            try {
                JourneyPage::parse($bad, 'some_journey');
                self::fail(json_encode($bad) . ' must not parse');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('some_journey', $e->getMessage());
            }
        }
    }

    /**
     * E3, and it is deliberate rather than an oversight. `Controller\PageContext` demands 32 hex
     * characters because it parses an untrusted HTTP payload. A journey file is committed source and
     * the eval catalogue is the fixture, whose ids look like `fx-026-blue-m`. Enforcing the hex rule
     * here would make every journey in this suite unwritable.
     */
    public function testFixtureShapedIdsAreAcceptedBecauseTheEvalCatalogueIsTheFixture(): void
    {
        self::assertSame('fx-004-black', JourneyPage::parse(['productId' => 'fx-004-black'], 'j')->productId);
    }
}
