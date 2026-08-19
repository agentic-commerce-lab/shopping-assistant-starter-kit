<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm;

use Swag\AssistantStarterKit\Core\Llm\Egress\ValidatingHttpClient;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlatformFactory
{
    public static function create(LlmSettings $settings, ?HttpClientInterface $httpClient = null): PlatformInterface
    {
        $guarded = new ValidatingHttpClient(
            $httpClient ?? HttpClient::create(['timeout' => 30]),
            allowInsecure: $settings->allowInsecureEgress,
        );

        return Factory::createPlatform(
            baseUrl: $settings->baseUrl,
            apiKey: $settings->apiKey,
            httpClient: $guarded,
            supportsEmbeddings: false,
        );
    }
}
