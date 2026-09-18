<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderQuery;

/**
 * The query a tool hands the gateway, already bounded and already validated.
 *
 * Both filters are the risky half of this feature: they are the only values the MODEL supplies that
 * reach a database query. So neither crosses the gateway boundary unchecked — `state` is matched
 * against a closed vocabulary of Shopware's own technical names and dropped when it is anything
 * else, and `withinDays` is clamped rather than refused, because a model asking for ten years of
 * history wants the list and would spend another call on an error.
 *
 * Dropped rather than refused, for `state`, is the deliberate half: an unknown state is a model
 * inventing a word, and answering the unfiltered question is closer to what the shopper asked than
 * refusing the turn over a word they never said.
 */
final class OrderQueryTest extends TestCase
{
    #[DataProvider('states')]
    public function testKeepsOnlyShopwaresOwnStateNames(?string $given, ?string $kept): void
    {
        self::assertSame($kept, OrderQuery::of(5, null, $given)->state);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function states(): array
    {
        return [
            'open' => ['open', 'open'],
            'in progress' => ['in_progress', 'in_progress'],
            'completed' => ['completed', 'completed'],
            'cancelled' => ['cancelled', 'cancelled'],
            'case and spacing are the model being loose' => ['  Completed ', 'completed'],
            'a word Shopware does not have' => ['shipped', null],
            'an injection attempt' => ["open' OR 1=1", null],
            'nothing asked' => [null, null],
        ];
    }

    #[DataProvider('windows')]
    public function testClampsTheWindowRatherThanRefusingIt(?int $given, ?int $kept): void
    {
        self::assertSame($kept, OrderQuery::of(5, $given, null)->withinDays);
    }

    /** @return array<string, array{?int, ?int}> */
    public static function windows(): array
    {
        return [
            'a month' => [30, 30],
            'nothing asked' => [null, null],
            'zero is not a window' => [0, 1],
            'negative' => [-7, 1],
            'ten years' => [3650, 730],
        ];
    }

    public function testClampsTheLimitTheSameWayTheToolDid(): void
    {
        self::assertSame(10, OrderQuery::of(99, null, null)->limit);
        self::assertSame(1, OrderQuery::of(0, null, null)->limit);
        self::assertSame(5, OrderQuery::of(5, null, null)->limit);
    }
}
