<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm;

use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Every model name is an embedding model.
 *
 * **Why this exists.** The generic bridge's {@see FallbackModelCatalog} decides what kind of model a
 * name refers to by looking for the substring "embed" in it — so `text-embedding-3-small` routes to
 * the embeddings client and `bge-m3` routes to the completions client. On
 * {@see PlatformFactory::createEmbeddings()}'s platform there is no completions client by
 * construction, so any model whose name does not happen to contain "embed" fails with *"No
 * ModelClient registered"* before a single HTTP request is made.
 *
 * That heuristic is reasonable for a platform serving both capabilities and simply wrong for one that
 * can only embed: there is nothing else a model name could mean here. Measured on 2026-08-25 — this is
 * what stood between this plugin and every embedding model not named by OpenAI, including the
 * multilingual ones that matter for German legal text (`bge-m3`, `multilingual-e5-large`).
 *
 * `getModels()` returns `[]` because nothing is pre-registered: any name the merchant configures is
 * accepted and handed to the provider, which is the authority on whether it exists.
 */
final readonly class EmbeddingsOnlyModelCatalog implements ModelCatalogInterface
{
    public function getModel(string $modelName): Model
    {
        return new EmbeddingsModel($modelName, Capability::cases());
    }

    public function getModels(): array
    {
        return [];
    }
}
