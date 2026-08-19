<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm\Egress;

use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorates an `HttpClientInterface` and validates every outgoing request against the
 * SSRF guard, not just the merchant-configured base URL. A redirect or a rebound DNS
 * entry would otherwise walk straight past a one-off check performed only at
 * configuration time.
 *
 * `$allowInsecure` exists solely for local development (e.g. reaching a local model
 * runner over plain HTTP on localhost). When set, it bypasses both the scheme and the
 * public-host checks below; it must default to `false` and must never be wired up to
 * merchant configuration.
 */
final class ValidatingHttpClient implements HttpClientInterface
{
    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        private readonly bool $allowInsecure = false,
    ) {
        $this->client = $client;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->guard($url);

        $options['max_redirects'] = 0;

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    private function guard(string $url): void
    {
        if ($this->allowInsecure) {
            return;
        }

        if (parse_url($url, \PHP_URL_SCHEME) !== 'https') {
            throw new LlmException('LLM egress requires https.');
        }

        $host = parse_url($url, \PHP_URL_HOST);

        if (!\is_string($host) || !HostValidator::isPublicHost($host)) {
            throw new LlmException('LLM egress requires a public host.');
        }
    }
}
