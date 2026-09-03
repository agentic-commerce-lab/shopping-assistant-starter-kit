<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The two rules about what a reply may assert ABOUT THE SHOP rather than about a product, in their
 * own class rather than in {@see SystemPromptTest}: that one sits on mago's per-class method cap, and
 * this project already splits prompt tests out for exactly that reason — see
 * {@see SystemPromptMatchReasonsTest}.
 *
 * Both were reported from staging on the same day, 2026-09-03, and both are the same mistake in
 * opposite directions: one claim the model would not make and should have, one it made and could not
 * support.
 */
final class SystemPromptShopWideClaimsTest extends TestCase
{
    /**
     * The dead end reported from staging on 2026-09-03: a shopper asked for purple tyres and the
     * turn ended on *"It may be that the shop has none in stock right now, or that the search words
     * didn't match — but I can't tell which."*
     *
     * The absence prohibition is right about PRODUCTS and was silently also gagging the one absolute
     * claim the shop can prove. When a tool reports an attribute as unrecorded, saying so is the
     * answer — see {@see \Swag\AssistantStarterKit\Core\Tool\UnrecordedOptions}.
     */
    public function testTheAbsenceProhibitionCarvesOutUnrecordedAttributes(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('That prohibition is about PRODUCTS and nothing below', $prompt);
        // The leak this closed: the carve-out used to MODEL the sentence as "this shop does not
        // record a colour for its tyres", and `no_match_not_absence` came back with "The shop does
        // not carry" — the same stem, a different verb. The example is now about the products.
        self::assertStringContainsString('and never about the shop', $prompt);
        self::assertStringNotContainsString('"this shop does not record', $prompt);
    }

    /**
     * Reported from staging, 2026-09-03: *"The Plus Tyre 650b is the largest tyre the shop has."*
     *
     * The prompt authorised the price superlative through `sort` and said nothing at all about the
     * others, so the model compared 650x47 against 700x28 inside an eight-product window and
     * reported it as a fact about the catalogue. `sort` is price-only, so no other superlative can
     * ever be the shop's to settle.
     */
    public function testOnlyThePriceSuperlativeIsTheShopsToSettle(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('the only superlatives the shop can settle', $prompt);
    }
}
