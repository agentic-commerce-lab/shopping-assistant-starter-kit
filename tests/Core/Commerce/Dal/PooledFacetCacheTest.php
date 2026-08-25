<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\PooledFacetCache;

/**
 * The cache key, which is the only part of this class that can be wrong in a way that matters.
 *
 * The DAL builds facets from the sales-channel context, so two channels have genuinely different
 * vocabularies. A key that ignored the channel would serve one shop's spellings as another's — a
 * cross-tenant leak introduced by an optimisation, which is the worst way to acquire one. Everything
 * else here is PSR-6 plumbing.
 */
final class PooledFacetCacheTest extends TestCase
{
    private const CHANNEL_A = '01a01b4af6567284ac9eeb3616598ac3';

    private const CHANNEL_B = 'ffffffffffffffffffffffffffffffff';

    public function testTwoSalesChannelsNeverShareAnEntry(): void
    {
        self::assertNotSame(
            PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope'),
            PooledFacetCache::cacheKey(self::CHANNEL_B, 'scope'),
            'one channel must not be able to read another channel vocabulary',
        );
    }

    public function testTwoScopesNeverShareAnEntry(): void
    {
        self::assertNotSame(
            PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope-one'),
            PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope-two'),
        );
    }

    public function testTheSameInputsGiveTheSameKey(): void
    {
        self::assertSame(
            PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope'),
            PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope'),
        );
    }

    /**
     * A version token, so a change to the cached DTO's shape cannot deserialize stale bytes into the
     * new class. Bumping it is the whole invalidation procedure.
     */
    public function testTheKeyCarriesAVersionToken(): void
    {
        self::assertStringContainsString('v1', PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope'));
    }

    /** PSR-6 reserves `{}()/\@:` in key names, and a pool is allowed to reject a key containing them. */
    public function testTheKeyContainsNoCharacterPsrSixReserves(): void
    {
        $key = PooledFacetCache::cacheKey(self::CHANNEL_A, 'scope');

        self::assertSame(0, preg_match('/[{}()\/\\\\@:]/', $key), \sprintf('unsafe key: %s', $key));
    }
}
