<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\ShopInfo\CmsPageText;

/**
 * Pulling prose out of a CMS page's slot configurations.
 *
 * The spec (R1) deferred CMS extraction to its own plan precisely because this is where the failure
 * modes are: text distributed across slot configurations, empty slots, slot types that hold no prose
 * at all, and HTML remnants. Every one of those is a case below, and each is a way to produce a
 * document that indexes cleanly and answers nothing.
 */
final class CmsPageTextTest extends TestCase
{
    public function testItReadsTheHtmlOutOfATextSlot(): void
    {
        $html = CmsPageText::fromSlots([
            ['type' => 'text', 'config' => ['content' => ['value' => '<h2>Impressum</h2><p>Musterstrasse 12</p>']]],
        ]);

        self::assertSame('<h2>Impressum</h2><p>Musterstrasse 12</p>', $html);
    }

    /**
     * A real page is several blocks, and the spec named this as the first thing to get wrong: the text
     * is *distributed*, so reading one slot yields a document that looks fine and is a fragment.
     */
    public function testItJoinsEverySlotThatCarriesProse(): void
    {
        $html = CmsPageText::fromSlots([
            ['type' => 'text', 'config' => ['content' => ['value' => '<h2>Widerruf</h2>']]],
            ['type' => 'text', 'config' => ['content' => ['value' => '<p>Binnen vierzehn Tagen.</p>']]],
        ]);

        self::assertSame('<h2>Widerruf</h2>' . "\n" . '<p>Binnen vierzehn Tagen.</p>', $html);
    }

    /** Slot types that hold no prose contribute nothing rather than an empty line. */
    public function testSlotsWithoutProseAreSkipped(): void
    {
        $html = CmsPageText::fromSlots([
            ['type' => 'image', 'config' => ['media' => ['value' => 'abc123']]],
            ['type' => 'text', 'config' => ['content' => ['value' => '<p>Gilt ab sofort.</p>']]],
            ['type' => 'product-slider', 'config' => ['products' => ['value' => []]]],
        ]);

        self::assertSame('<p>Gilt ab sofort.</p>', $html);
    }

    public function testAnEmptySlotContributesNothing(): void
    {
        $html = CmsPageText::fromSlots([
            ['type' => 'text', 'config' => ['content' => ['value' => '   ']]],
            ['type' => 'text', 'config' => ['content' => ['value' => null]]],
            ['type' => 'text', 'config' => []],
        ]);

        self::assertSame('', $html);
    }

    /**
     * A page whose slots hold nothing must yield an empty string, so the caller can refuse it.
     *
     * The dangerous outcome is the opposite: a page that yields whitespace, extracts to nothing, and
     * is recorded as an indexed document with no passages behind it.
     */
    public function testAPageWithNoSlotsAtAllIsEmpty(): void
    {
        self::assertSame('', CmsPageText::fromSlots([]));
    }
}
