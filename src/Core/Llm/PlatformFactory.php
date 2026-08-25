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
        return Factory::createPlatform(
            baseUrl: $settings->baseUrl,
            apiKey: $settings->apiKey,
            httpClient: self::guarded($settings, $httpClient),
            supportsEmbeddings: false,
        );
    }

    /**
     * A platform that can only embed, for shop information retrieval.
     *
     * **A second platform rather than flipping `supportsEmbeddings` on the first one.** Enabling both
     * capabilities puts two model clients behind one router, and the router picks between them from a
     * model catalog that, for an arbitrary OpenAI-compatible provider, is the fallback catalog. A
     * completion routed to the embeddings endpoint would break every existing turn — so the
     * completion platform is left exactly as it was, and this one is the mirror image: embeddings
     * only, no completions.
     *
     * Same provider URL and key as the chat model, which is what the `embeddingModel` setting's help
     * text promises. Note that this requires the provider to serve `/v1/embeddings`: a gateway that
     * only proxies chat completions will answer 404 here, and that is a configuration problem rather
     * than a code one.
     */
    public static function createEmbeddings(
        LlmSettings $settings,
        ?HttpClientInterface $httpClient = null,
    ): PlatformInterface {
        return Factory::createPlatform(
            baseUrl: $settings->baseUrl,
            apiKey: $settings->apiKey,
            httpClient: self::guarded($settings, $httpClient),
            supportsCompletions: false,
            supportsEmbeddings: true,
        );
    }

    /** Egress validation applies to embedding calls exactly as it does to completions. */
    private static function guarded(LlmSettings $settings, ?HttpClientInterface $httpClient): HttpClientInterface
    {
        return new ValidatingHttpClient(
            $httpClient ?? HttpClient::create(['timeout' => 30]),
            allowInsecure: $settings->allowInsecureEgress,
        );
    }
}
