<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\DisclosedToolNames;

/**
 * The signal behind the disclosure guard, and the reason it is this signal and not a classifier.
 *
 * Two of nine injection attempts in the 34-conversation export of 2026-09-09 talked the assistant
 * into describing itself — one recited every tool by name, the other roughly 1,200 words of the
 * system prompt. Run over all 104 replies, this rule flags 6 and every one is a genuine disclosure.
 */
#[CoversClass(DisclosedToolNames::class)]
final class DisclosedToolNamesTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const SHIPPED = [
        'search_products',
        'get_product',
        'compare_products',
        'add_to_cart',
        'go_to_checkout',
        'escalate',
        'search_shop_info',
    ];

    public function testItFindsAToolNameARecitedInventoryContains(): void
    {
        $reply =
            'Ich habe Zugang zu folgenden Tools: 1. search_products — Suche nach Produkten. '
            . '2. add_to_cart — legt Artikel in den Warenkorb.';

        self::assertSame(['search_products', 'add_to_cart'], DisclosedToolNames::in($reply, self::SHIPPED));
    }

    public function testAnOrdinaryShopperFacingReplyIsNotFlagged(): void
    {
        $reply = 'The Gravel Helmet comes in M and L, and I can add one to your cart if you like.';

        self::assertSame([], DisclosedToolNames::in($reply, self::SHIPPED));
    }

    /**
     * **`escalate` is deliberately not looked for.** It is the one shipped name that is also an
     * ordinary English verb, and immunity from every future reply that uses the word as a word is
     * worth more than the one detection it costs.
     */
    public function testTheOneToolNameThatIsAlsoAnEnglishWordIsIgnored(): void
    {
        self::assertSame([], DisclosedToolNames::in('I can escalate this to the shop team.', self::SHIPPED));
        self::assertSame([], DisclosedToolNames::in('definierte Tools wie `escalate`', self::SHIPPED));
    }

    /**
     * A longer identifier that merely contains a tool name is not that tool — `\b` would not do,
     * because PCRE puts a word boundary between a letter and an underscore.
     */
    public function testItIsBoundedOnBothSides(): void
    {
        self::assertSame([], DisclosedToolNames::in('see my_add_to_cart_helper for details', self::SHIPPED));
        self::assertSame(['add_to_cart'], DisclosedToolNames::in('the "add_to_cart" tool', self::SHIPPED));
    }

    public function testCasingDoesNotHideADisclosure(): void
    {
        self::assertSame(['search_products'], DisclosedToolNames::in('### Search_Products', self::SHIPPED));
    }

    /**
     * The names come from the turn's own toolbox rather than a constant here, so a tool contributed
     * by an extension — which `docs/extending.md` presents as supported — is covered without this
     * class knowing about it.
     */
    public function testAToolNameFromAnExtensionIsCoveredToo(): void
    {
        $names = [...self::SHIPPED, 'check_store_stock'];

        self::assertSame(['check_store_stock'], DisclosedToolNames::in('I call check_store_stock for that.', $names));
    }

    public function testARepeatedNameIsReportedOnce(): void
    {
        $reply = 'search_products does the search, and search_products again for more.';

        self::assertSame(['search_products'], DisclosedToolNames::in($reply, self::SHIPPED));
    }
}
