<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The tables {@see ShopInfoCommand} prints.
 *
 * Split out for the same reason as {@see ProbeRenderer}: table building is loops and formatting, and
 * leaving it in the command puts it in the same complexity budget as the command's dispatch.
 */
final readonly class ShopInfoRenderer
{
    private const TEXT_PREVIEW_CHARS = 60;

    /** @param list<ShopInfoDocument> $documents */
    public function documents(SymfonyStyle $io, array $documents): void
    {
        $rows = [];

        foreach ($documents as $document) {
            $rows[] = [
                $document->name,
                $document->status,
                (string) $document->chunkCount,
                (string) $document->dimension,
                $document->statusReason,
            ];
        }

        $io->table(['Name', 'Status', 'Chunks', 'Dim', 'Reason'], $rows);
    }

    /**
     * Every score, rejected ones included — the point of `--query` (spec R4, R5).
     *
     * @param list<ShopInfoPassage> $passages
     */
    public function passages(SymfonyStyle $io, array $passages): void
    {
        $rows = [];

        foreach ($passages as $passage) {
            $rows[] = [
                \sprintf('%.4f', $passage->score),
                $passage->documentName,
                $passage->section,
                mb_substr($passage->text, 0, self::TEXT_PREVIEW_CHARS),
            ];
        }

        $io->table(['Score', 'Document', 'Section', 'Text'], $rows);
    }
}
