<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * A {@see PassageStore} that lives in this process — no database, no schema, nothing to clean up.
 *
 * **Why this ships in `src` rather than in the test suite.** The eval harness under `src/Eval` needs
 * it, and `src` cannot autoload `tests`. Duplicating it on both sides would put two copies of the
 * width invariant in a repo whose quality gate includes duplicate detection, and the copies would
 * drift. So it lives here and the unit tests use this one.
 *
 * **It is never the container's `PassageStore`.** `services.xml` aliases that to
 * {@see AiStorePassageStore}; this one has no service definition at all. A shop that somehow got this
 * would lose every passage on each request, which is exactly why the alias is explicit.
 *
 * Its scoring mirrors what the real store's callers see after that store converts its own distance —
 * see {@see PassageStore} on the sign.
 */
final class InMemoryPassageStore implements PassageStore
{
    /** @var list<array{passage: ShopInfoPassage, vector: list<float>, salesChannelId: string}> */
    private array $rows = [];

    public function add(array $passages, array $vectors, string $salesChannelId): void
    {
        if (\count($passages) !== \count($vectors)) {
            throw new \RuntimeException('one vector per passage is required');
        }

        StoreWidth::guard($this->dimension(), $vectors);

        foreach ($passages as $index => $passage) {
            $this->rows[] = [
                'passage' => $passage,
                'vector' => $vectors[$index] ?? throw new \RuntimeException('no vector for passage'),
                'salesChannelId' => $salesChannelId,
            ];
        }
    }

    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        $candidates = [];

        foreach ($this->rows as $row) {
            if ($row['salesChannelId'] !== $salesChannelId) {
                continue;
            }

            $candidates[] = ['passage' => $row['passage'], 'vector' => $row['vector']];
        }

        return PassageRanking::of($vector, $candidates, $minScore, $limit);
    }

    public function deleteDocument(string $documentId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['passage']->documentId !== $documentId,
        ));
    }

    public function dimension(): ?int
    {
        $first = $this->rows[0] ?? null;

        return $first === null ? null : \count($first['vector']);
    }
}
