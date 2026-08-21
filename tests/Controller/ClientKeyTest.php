<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\ClientKey;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the per-client rate window is counted against.
 *
 * The IP is the only thing a caller cannot rotate for free — a conversation token is minted on
 * request, so counting against one would let a loop reset its own limit every message.
 */
final class ClientKeyTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const OTHER_CHANNEL = '02b02c5bf6678395bd0ffc4727609bd4';

    public function testTheSameCallerOnTheSameChannelGetsTheSameKey(): void
    {
        self::assertSame(
            ClientKey::of($this->requestFrom('198.51.100.7'), self::CHANNEL),
            ClientKey::of($this->requestFrom('198.51.100.7'), self::CHANNEL),
        );
    }

    public function testTwoCallersGetDifferentKeys(): void
    {
        self::assertNotSame(
            ClientKey::of($this->requestFrom('198.51.100.7'), self::CHANNEL),
            ClientKey::of($this->requestFrom('203.0.113.9'), self::CHANNEL),
        );
    }

    public function testOneCallerIsCountedSeparatelyPerSalesChannel(): void
    {
        // The limit is a per-channel merchant setting, so the counter has to be per channel too —
        // otherwise a shopper browsing two of a merchant's storefronts shares one allowance.
        self::assertNotSame(
            ClientKey::of($this->requestFrom('198.51.100.7'), self::CHANNEL),
            ClientKey::of($this->requestFrom('198.51.100.7'), self::OTHER_CHANNEL),
        );
    }

    public function testTheKeyDoesNotContainTheAddressItWasDerivedFrom(): void
    {
        // The key becomes a cache key, and cache keys turn up in Redis dumps and debug output. An IP
        // is personal data; a digest of one answers "same caller?" without storing who.
        $key = ClientKey::of($this->requestFrom('198.51.100.7'), self::CHANNEL);

        self::assertStringNotContainsString('198.51.100.7', $key);
    }

    public function testARequestWithNoResolvableAddressStillYieldsAKey(): void
    {
        // Every caller must land in *some* window. Returning an empty key would mean an unbounded
        // one, so an unknown address shares a single bucket rather than escaping the limit.
        $request = Request::create('/assistant/chat', 'POST');
        $request->server->remove('REMOTE_ADDR');

        self::assertNotSame('', ClientKey::of($request, self::CHANNEL));
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('/assistant/chat', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }
}
