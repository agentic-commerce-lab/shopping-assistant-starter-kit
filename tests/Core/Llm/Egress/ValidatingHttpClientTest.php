<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm\Egress;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\Egress\ValidatingHttpClient;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ValidatingHttpClientTest extends TestCase
{
    public function testBlocksARequestToAPrivateAddressEvenIfTheBaseUrlWasFine(): void
    {
        $client = new ValidatingHttpClient(new MockHttpClient(new MockResponse('{}')));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('public host');

        $client->request('POST', 'https://169.254.169.254/v1/chat/completions');
    }

    public function testPassesAPublicRequestThrough(): void
    {
        $client = new ValidatingHttpClient(new MockHttpClient(new MockResponse('{"ok":true}')));

        $response = $client->request('POST', 'https://1.1.1.1/v1/chat/completions');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsPlainHttp(): void
    {
        $client = new ValidatingHttpClient(new MockHttpClient(new MockResponse('{}')));

        $this->expectExceptionMessage('https');

        $client->request('POST', 'http://1.1.1.1/v1/chat/completions');
    }
}
