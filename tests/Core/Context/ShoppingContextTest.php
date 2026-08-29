<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Context;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;

/**
 * `matches()` is the whole security boundary of this plan in one method: it decides whether a
 * presented conversation token may open a stored transcript. Every case here is a way two shoppers
 * could be confused for each other.
 */
final class ShoppingContextTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const OTHER_CHANNEL = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function customer(string $customerId, string $channel = self::CHANNEL): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, $channel, $customerId);
    }

    public function testTheSameCustomerOnTheSameChannelMatches(): void
    {
        self::assertTrue($this->customer(self::ALICE)->matches($this->customer(self::ALICE)));
    }

    public function testTwoCustomersNeverMatch(): void
    {
        // The defect this plan exists to close: Bob presenting Alice's token.
        self::assertFalse($this->customer(self::ALICE)->matches($this->customer(self::BOB)));
    }

    public function testTheSameCustomerOnADifferentSalesChannelDoesNotMatch(): void
    {
        // One account, two storefronts — different catalogue, different currency, different
        // conversation. A transcript from one must not surface in the other.
        self::assertFalse($this->customer(self::ALICE)->matches($this->customer(self::ALICE, self::OTHER_CHANNEL)));
    }

    public function testAGuestNeverMatchesACustomer(): void
    {
        // Both directions, because a guest token surviving a login and a customer token surviving a
        // logout are two different bugs with the same fix.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertFalse($guest->matches($this->customer(self::ALICE)));
        self::assertFalse($this->customer(self::ALICE)->matches($guest));
    }

    public function testTwoGuestsOnTheSameChannelMatch(): void
    {
        // Guests are not told apart, and must not be: there is no identity to compare. What keeps
        // one guest out of another's transcript is the token itself, which never leaves their
        // browser. See the class docblock.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertTrue($guest->matches(new ShoppingContext(ShoppingMode::Guest, self::CHANNEL)));
    }

    public function testAnEmployeeIsComparedEvenThoughNothingSetsOneYet(): void
    {
        // Null today, populated when the Commercial bridge lands. Comparing it now means the bridge
        // adds a resolver and nothing else — and that two colleagues of one company, who present
        // the *same* customer id, are already told apart by this method.
        $alice = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, employeeId: 'e1');
        $colleague = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, employeeId: 'e2');

        self::assertFalse($alice->matches($colleague));
        self::assertTrue($alice->matches(
            new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, employeeId: 'e1'),
        ));
    }

    public function testAnOrganisationIsComparedTheSameWay(): void
    {
        $berlin = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, organisationId: 'o1');
        $munich = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, organisationId: 'o2');

        self::assertFalse($berlin->matches($munich));
    }
}
