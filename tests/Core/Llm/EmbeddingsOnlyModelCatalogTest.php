<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\EmbeddingsOnlyModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;

/**
 * The catalog that lets this plugin use an embedding model whose name does not contain "embed".
 *
 * The generic bridge's own fallback catalog decides a model's kind from that substring, so `bge-m3`
 * was routed to a completions client the embeddings-only platform does not have — failing with "No
 * ModelClient registered" before any request. Measured against the real provider on 2026-08-25: this
 * stood between the plugin and every multilingual embedding model, which are exactly the ones that
 * matter for German legal text.
 */
final class EmbeddingsOnlyModelCatalogTest extends TestCase
{
    /**
     * @return list<array{non-empty-string}>
     */
    public static function modelNames(): array
    {
        return [
            // The case that was broken: no "embed" anywhere in the name.
            ['bge-m3'],
            ['baai/bge-m3'],
            ['multilingual-e5-large'],
            // And the case that already worked, which must keep working.
            ['openai/text-embedding-3-small'],
        ];
    }

    /** @param non-empty-string $modelName */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelNames')]
    public function testEveryModelNameIsAnEmbeddingModel(string $modelName): void
    {
        self::assertInstanceOf(EmbeddingsModel::class, (new EmbeddingsOnlyModelCatalog())->getModel($modelName));
    }

    public function testNothingIsPreRegisteredSoAnyProviderModelIsAccepted(): void
    {
        // The provider is the authority on which models exist; a hard-coded list here would be a
        // second place to keep in sync and a reason for a valid model to be refused.
        self::assertSame([], (new EmbeddingsOnlyModelCatalog())->getModels());
    }
}
