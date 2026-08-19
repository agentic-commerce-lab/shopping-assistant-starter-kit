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
            'cart_contains' => new CartContains(),
            default => throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares unknown assertion "%s".',
                $journeyId,
                $name,
            )),
        };
    }
}
