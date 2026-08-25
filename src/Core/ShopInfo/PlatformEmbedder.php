<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * {@see Embedder} over `symfony/ai-store`'s `Vectorizer`.
 *
 * The whole class is the narrowing: the library's `vectorize()` returns a union of four shapes, and
 * this turns the one shape we asked for into `list<list<float>>` — or fails saying what it got
 * instead. Doing that once here is why no caller has to.
 *
 * A provider that answers with something unexpected fails loudly rather than yielding a short or
 * empty vector list, because a missing vector would silently drop a chunk: the document would report
 * fewer passages than it has and the missing one would be unretrievable for ever.
 */
final readonly class PlatformEmbedder implements Embedder
{
    public function __construct(
        private VectorizerInterface $vectorizer,
    ) {}

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $result = $this->vectorizer->vectorize($texts);

        if (!\is_array($result)) {
            throw new \RuntimeException('The embedding provider returned a single vector for a batch.');
        }

        $vectors = [];

        foreach ($result as $vector) {
            if (!$vector instanceof Vector) {
                throw new \RuntimeException('The embedding provider returned something that is not a vector.');
            }

            $vectors[] = $vector->getData();
        }

        if (\count($vectors) !== \count($texts)) {
            throw new \RuntimeException(\sprintf(
                'Asked the embedding provider for %d vectors and got %d. Indexing would silently ' . 'drop a passage.',
                \count($texts),
                \count($vectors),
            ));
        }

        return $vectors;
    }
}
