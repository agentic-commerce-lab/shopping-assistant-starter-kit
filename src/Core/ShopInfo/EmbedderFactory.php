<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\PlatformFactory;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An {@see Embedder} for one sales channel, using that channel's provider and embedding model.
 *
 * Per channel because the settings are: one shop can point two channels at two different providers,
 * and the vectors in the store are only comparable within one of them.
 *
 * **Empty model throws rather than falling back.** Spec R13 makes an empty `embeddingModel` the off
 * switch for the whole feature, so the tool factory returns null before ever reaching this and
 * ingestion refuses at the CLI. Reaching here with an empty model is a wiring mistake, and a default
 * model nobody chose would hide it behind a provider bill.
 */
final readonly class EmbedderFactory
{
    public function __construct(
        private SystemConfigLlmSettings $llmSettings,
        private ?HttpClientInterface $httpClient = null,
    ) {}

    public function forSalesChannel(string $salesChannelId, string $embeddingModel): Embedder
    {
        if ($embeddingModel === '') {
            throw new \RuntimeException(
                'No embedding model is configured, so shop information is switched off. Set '
                . '"Embedding model" in the plugin settings.',
            );
        }

        $llm = $this->llmSettings->forSalesChannel($salesChannelId);

        $platform = PlatformFactory::createEmbeddings(
            new LlmSettings(baseUrl: $llm->baseUrl, apiKey: $llm->apiKey, model: $embeddingModel),
            $this->httpClient,
        );

        return new PlatformEmbedder(new Vectorizer($platform, $embeddingModel));
    }
}
