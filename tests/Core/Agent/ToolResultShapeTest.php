<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\ToolResultShape;

/**
 * The three questions a trace review could not answer, turned into a query.
 *
 * All three had one cause: the traces recorded that a tool was called and never what it returned.
 */
#[CoversClass(ToolResultShape::class)]
final class ToolResultShapeTest extends TestCase
{
    public function testItRecordsTheCountsAReviewNeedsToCheckASentenceAgainst(): void
    {
        $shape = ToolResultShape::of([
            'products' => [['id' => 'a', 'name' => 'Gravel Helmet'], ['id' => 'b', 'name' => 'Trail Helmet']],
            'total' => 2,
            'matched' => 16,
            'more' => false,
            'withheld' => 14,
        ]);

        self::assertSame(2, $shape['total'] ?? null);
        self::assertSame(16, $shape['matched'] ?? null);
        self::assertSame(14, $shape['withheld'] ?? null);
        self::assertFalse($shape['more'] ?? null);
    }

    /**
     * No product name, no description, no note text &mdash; the card rows already hold what was shown,
     * and a merchant-readable audit row is not a second copy of the catalogue.
     */
    public function testItCopiesNoProductText(): void
    {
        $shape = ToolResultShape::of([
            'products' => [['id' => 'a', 'name' => 'Gravel Helmet']],
            'note' => 'This search matched nothing.',
        ]);

        self::assertStringNotContainsString('Gravel Helmet', json_encode($shape, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('matched nothing', json_encode($shape, \JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a note was attached is the question, and the key list answers it &mdash; without copying
     * the sentence, which is a constant in this codebase anyway.
     */
    public function testItRecordsThatANoteWasAttachedWithoutItsWording(): void
    {
        $shape = ToolResultShape::of(['note' => 'This search matched nothing.']);

        self::assertSame('note', $shape['keys'] ?? null);
        self::assertStringNotContainsString('matched nothing', json_encode($shape, \JSON_THROW_ON_ERROR));
    }

    /**
     * The second unanswerable question: was the model ever handed the shop's real departments?
     * `shop_sells` is the key {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation} writes.
     */
    public function testItRecordsWhetherTheDepartmentsWereHandedOver(): void
    {
        $shape = ToolResultShape::of(['note' => 'n', 'shop_sells' => ['Apparel', 'Bags', 'Brakes']]);

        self::assertSame('note,shop_sells', $shape['keys'] ?? null);
        self::assertStringNotContainsString('Apparel', json_encode($shape, \JSON_THROW_ON_ERROR));
    }

    /**
     * `add_to_cart` and `go_to_checkout` read the shopper's live cart, so their replies carry that
     * shopper's basket. The count is the fact worth keeping; the contents are not ours to copy.
     */
    public function testACartIsNeverCopied(): void
    {
        $shape = ToolResultShape::of([
            'lineItems' => [['name' => 'Road Helmet Aero', 'quantity' => 1]],
            'quantity' => 1,
        ]);

        self::assertSame(1, $shape['quantity'] ?? null);
        self::assertStringNotContainsString('Road Helmet', json_encode($shape, \JSON_THROW_ON_ERROR));
    }

    public function testTheKeysAreAlwaysRecordedSoAnUnknownShapeIsStillVisible(): void
    {
        $shape = ToolResultShape::of(['products' => [], 'total' => 0, 'something_new' => 'x']);

        self::assertSame('products,total,something_new', $shape['keys'] ?? null);
    }

    public function testANonArrayResultIsDescribedByItsTypeAlone(): void
    {
        self::assertSame(['resultType' => 'string'], ToolResultShape::of('a plain string'));
    }
}
