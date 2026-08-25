<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * @internal a store that lives in this process, so the tool and ingestion are testable with no
 *           database, no API key and no embedding call
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
        $scored = [];

        foreach ($this->rows as $row) {
            if ($row['salesChannelId'] !== $salesChannelId) {
                continue;
            }

            $score = CosineSimilarity::between($vector, $row['vector']);

            if ($score < $minScore) {
                continue;
            }

            $passage = $row['passage'];
            $scored[] = new ShopInfoPassage(
                $passage->documentId,
                $passage->documentName,
                $passage->section,
                $passage->text,
                $score,
            );
        }

        usort($scored, static fn(ShopInfoPassage $a, ShopInfoPassage $b): int => $b->score <=> $a->score);

        return \array_slice($scored, offset: 0, length: $limit);
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
