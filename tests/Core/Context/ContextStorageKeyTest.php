<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Context;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ContextStorageKey;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;

/**
 * The key reaches the browser, so the one thing it must never do is carry an identity. It is a slot
 * name, not a credential — the server re-validates the conversation token against the real context
 * on every request regardless of which slot it came from.
 */
final class ContextStorageKeyTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function keys(): ContextStorageKey
    {
        return new ContextStorageKey('test-kernel-secret');
    }

    private function customer(string $id): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, $id);
    }

    public function testTheSameScopeAlwaysProducesTheSameKey(): void
    {
        // Otherwise a shopper loses their conversation on every page load.
        self::assertSame(
            $this->keys()->for($this->customer(self::ALICE)),
            $this->keys()->for($this->customer(self::ALICE)),
        );
    }

    public function testTwoCustomersGetDifferentSlots(): void
    {
        self::assertNotSame(
            $this->keys()->for($this->customer(self::ALICE)),
            $this->keys()->for($this->customer(self::BOB)),
        );
    }

    public function testGuestAndCustomerGetDifferentSlots(): void
    {
        // This is what makes logout leave the authenticated conversation untouched instead of
        // overwriting it with the guest one.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertNotSame($this->keys()->for($guest), $this->keys()->for($this->customer(self::ALICE)));
    }

    public function testTheKeyContainsNoIdentityInPlainText(): void
    {
        $key = $this->keys()->for($this->customer(self::ALICE));

        self::assertStringNotContainsString(self::ALICE, $key);
        self::assertStringNotContainsString(self::CHANNEL, $key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
    }

    public function testADifferentSecretProducesADifferentKey(): void
    {
        // Two shops sharing a browser profile — a staging and a live domain, say — must not share
        // conversation slots.
        self::assertNotSame(
            (new ContextStorageKey('one-secret'))->for($this->customer(self::ALICE)),
            (new ContextStorageKey('another-secret'))->for($this->customer(self::ALICE)),
        );
    }
}
