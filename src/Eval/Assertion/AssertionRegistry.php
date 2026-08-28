<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Eval\Assertion;

/**
 * Maps a journey's assertion name to its implementation for {@see \Swag\AssistantStarterKit\Eval\JourneyAssertions}.
 * The unconditional throw in the default arm is deliberate and load-bearing: a typo in
 * a journey file (e.g. "no_invented_produtc") must fail loudly at load time, never be
 * silently dropped or skipped.
 */
final class AssertionRegistry
{
    public static function resolve(string $name, string $journeyId): Assertion
    {
        return match ($name) {
            'no_invented_product' => new NoInventedProduct(),
            'price_matches_source' => new PriceMatchesSource(),
            'stock_matches_source' => new StockMatchesSource(),
            'blocklist_respected' => new BlocklistRespected(),
            'no_unbacked_price_in_prose' => new NoUnbackedPriceInProse(),
            'no_unbacked_property_claim_in_prose' => new NoUnbackedPropertyClaimInProse(),
            'cart_contains' => new CartContains(),
            'cart_quantity_stored' => new CartQuantityStored(),
            'rendered_ids_exactly' => new RenderedIdsExactly(),
            'no_absence_claim_in_prose' => new NoAbsenceClaimInProse(),
            'no_handoff_claim_in_prose' => new NoHandoffClaimInProse(),
            'escalated_with_handoff' => new EscalatedWithHandoff(),
            'tool_calls_at_most' => new ToolCallsAtMost(),
            'questions_at_most' => new QuestionsAtMost(),
            'renders_at_least' => new RendersAtLeast(),
            'rendered_ids_from_each' => new RenderedIdsFromEach(),
            'retrieved_shop_info' => new RetrievedShopInfo(),
            'no_unsupported_period_in_prose' => new NoUnsupportedPeriodInProse(),
            'rendered_family_spread' => new RenderedFamilySpread(),
            default => throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares unknown assertion "%s".',
                $journeyId,
                $name,
            )),
        };
    }
}
