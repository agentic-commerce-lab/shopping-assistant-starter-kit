<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\RequestBudget;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The window has to survive the storage being **reconstructed**, because that is what happens between
 * two HTTP requests: a new PHP process, a new container, a new cache-pool object.
 *
 * `RequestBudgetTest` reuses one `InMemoryStorage` for every call, so it proves the counting logic and
 * says nothing about persistence. Measured on the local shop on 2026-08-24: with `cache.app` bound to
 * `cache.adapter.array` — which is what a Shopware dev install ships — three requests against a limit
 * of two all returned 200, because each request began with an empty window. A security control that
 * reads as present and enforces nothing, which is the exact defect this feature was written to fix.
 *
 * Every case here therefore builds a **fresh** storage per simulated request.
 */
final class RequestBudgetPersistenceTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/swag-assistant-budget-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    public function testTheWindowSurvivesTheStorageBeingRebuilt(): void
    {
        $config = new AssistantConfig(requestsPerMinute: 2);

        // Three "requests", each with its own storage object over the same backing directory.
        self::assertTrue($this->request($config)->accepted, 'request 1 is within the limit');
        self::assertTrue($this->request($config)->accepted, 'request 2 is within the limit');

        $third = $this->request($config);

        self::assertFalse($third->accepted, 'request 3 exceeds a limit of 2 and must be refused');
        self::assertSame(RequestBudget::REASON_CLIENT_RATE, $third->reasonCode);
    }

    public function testTheDailyBudgetSurvivesItToo(): void
    {
        $config = new AssistantConfig(dailyRequestCap: 1);

        self::assertTrue($this->dailyRequest($config)->accepted);
        self::assertFalse($this->dailyRequest($config)->accepted);
    }

    public function testAnInMemoryPoolIsExactlyWhatDoesNotWork(): void
    {
        // The failing configuration, pinned so nobody re-introduces it by wiring the limiter to a
        // pool that happens to be `cache.adapter.array`. A fresh ArrayAdapter per request is an empty
        // window per request, so the limit is unreachable — three requests, limit of two, all
        // accepted. This test passing is the bug being *reproducible*, not the bug being present.
        $config = new AssistantConfig(requestsPerMinute: 2);

        $accepted = 0;
        for ($i = 0; $i < 3; $i++) {
            $budget = new RequestBudget(new CacheStorage(new ArrayAdapter()));
            $accepted += $budget->consumeClientWindow($config, 'a-client')->accepted ? 1 : 0;
        }

        self::assertSame(3, $accepted, 'an in-memory pool cannot enforce a limit across requests');
    }

    private function request(AssistantConfig $config): \Swag\AssistantStarterKit\Core\Policy\BudgetVerdict
    {
        return $this->freshBudget()->consumeClientWindow($config, 'a-client');
    }

    private function dailyRequest(AssistantConfig $config): \Swag\AssistantStarterKit\Core\Policy\BudgetVerdict
    {
        return $this->freshBudget()->consumeDailyBudget($config, 'a-channel');
    }

    /**
     * A new budget over a new pool object, same directory — one simulated HTTP request.
     */
    private function freshBudget(): RequestBudget
    {
        return new RequestBudget(new CacheStorage(
            new FilesystemAdapter('swag_assistant_budget_test', 0, $this->directory),
        ));
    }
}
