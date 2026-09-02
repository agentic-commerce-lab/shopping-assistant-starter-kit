<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\Egress\ProviderErrorRetryStrategy;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * The retry that catches a provider failure arriving as **HTTP 200**.
 *
 * Measured on staging 2026-09-02: about one turn in twenty died on
 * `Error "504"-- (-): "The operation was aborted"`, and the response carrying it had status **200**
 * with `{"error":{"code":504}}` in the body. A retry keyed on status codes — the obvious design, and
 * the one this project nearly shipped — would never have fired once.
 *
 * Driven through a real {@see RetryableHttpClient} rather than by calling the strategy directly:
 * `shouldRetry()` returning `null` means "wait for the body", and only the client implements that
 * second pass. A unit test of the method in isolation would assert the decision without proving the
 * retry.
 */
final class ProviderErrorRetryTest extends TestCase
{
    public function testATwoHundredCarryingAProviderErrorIsRetried(): void
    {
        $client = self::clientReturning([
            new MockResponse('{"error":{"message":"The operation was aborted","code":504}}'),
            new MockResponse('{"choices":[{"message":{"content":"the real answer"}}]}'),
        ]);

        $response = $client->request('POST', 'https://provider.test/v1/chat/completions');

        self::assertStringContainsString('the real answer', $response->getContent());
    }

    /** Two retries, so a single stalled upstream leg does not have to be the shopper's problem. */
    public function testItGivesUpAfterTwoRetriesRatherThanHammering(): void
    {
        $aborted = static fn(): MockResponse => new MockResponse('{"error":{"message":"aborted","code":504}}');
        $client = self::clientReturning([$aborted(), $aborted(), $aborted()]);

        $response = $client->request('POST', 'https://provider.test/v1/chat/completions');

        // The third body is returned rather than a fourth attempt being made: the error survives to
        // the platform, which turns it into the apology, exactly as before.
        self::assertStringContainsString('aborted', $response->getContent(false));
    }

    public function testACleanAnswerIsNotRetried(): void
    {
        $client = self::clientReturning([
            new MockResponse('{"choices":[{"message":{"content":"first"}}]}'),
            new MockResponse('{"choices":[{"message":{"content":"second"}}]}'),
        ]);

        self::assertStringContainsString('first', $client->request('POST', 'https://provider.test/v1')->getContent());
    }

    /**
     * A 4xx is the shop's own mistake — a bad key, a model this provider does not serve. Repeating it
     * turns one clear error into three and spends the shopper's wait on nothing.
     */
    public function testAClientErrorIsNotRetried(): void
    {
        $client = self::clientReturning([
            new MockResponse('{"error":{"message":"No auth credentials found","code":401}}', ['http_code' => 401]),
            new MockResponse('{"choices":[{"message":{"content":"never reached"}}]}'),
        ]);

        $response = $client->request('POST', 'https://provider.test/v1');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('never reached', $response->getContent(false));
    }

    /** An error the provider answered with, rather than a failure to answer, is left alone. */
    public function testATwoHundredCarryingASubFiveHundredCodeIsNotRetried(): void
    {
        $client = self::clientReturning([
            new MockResponse('{"error":{"message":"context length exceeded","code":400}}'),
            new MockResponse('{"choices":[{"message":{"content":"never reached"}}]}'),
        ]);

        self::assertStringContainsString(
            'context length',
            $client->request('POST', 'https://provider.test/v1')->getContent(false),
        );
    }

    /** @param list<MockResponse> $responses */
    private static function clientReturning(array $responses): RetryableHttpClient
    {
        return new RetryableHttpClient(new MockHttpClient($responses), new ProviderErrorRetryStrategy(), 2);
    }
}
