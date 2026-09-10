<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\SearchResultCounts;
use Swag\AssistantStarterKit\Core\Tool\WithheldCount;
use Swag\AssistantStarterKit\Tests\Support\BuildsProductCards;

/**
 * `withheld` counts products, not rows — and this is the session that decides it.
 *
 * `07b4c0f7`: a shopper asked for lights, then *"more please"*, then *"That are the same ones"*.
 * Retrieval had nine survivors across four families, {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier}
 * returns one card per family, and four cards was everything the shop had for that query at any
 * limit the model asked for. Counting rows would report five more lights and invite exactly the
 * promise the shopper was already pushing back on.
 */
#[CoversClass(WithheldCount::class)]
#[CoversClass(SearchResultCounts::class)]
final class WithheldCountsProductsTest extends TestCase
{
    use BuildsProductCards;

    public function testVariantsOfAProductAlreadyShownAreNotMoreProducts(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 4, each: 1),
            survivors: self::variantsOf(families: 4, each: 3),
            saturated: false,
        );

        self::assertSame(12, $counts['matched'], 'matched still counts rows, per T3');
        self::assertArrayNotHasKey('withheld', $counts, 'nothing more to SHOW — the rest are options');
    }

    /**
     * And the case it must still report: whole products nobody has seen — stated in the terms the
     * shopper would recognise. Three helmet models on screen, two more models to see, and the
     * seventeen remaining rows are sizes and colours of what they are already looking at.
     */
    public function testAProductNotShownAtAllIsWithheld(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 3, each: 1),
            survivors: self::variantsOf(families: 5, each: 4),
            saturated: false,
        );

        self::assertSame(2, $counts['withheld'] ?? null, 'two more products, not seventeen more rows');
    }
}
