<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;

/**
 * Paragraph-based chunking with overlap (spec R11).
 *
 * The reason it is paragraphs and not characters: a chunk that starts mid-sentence separates "binnen
 * vierzehn Tagen" from "ab Erhalt der Ware". The model then paraphrases a deadline with no start
 * date, and it reads like a fact.
 */
final class ChunkerTest extends TestCase
{
    public function testShortTextIsOneChunk(): void
    {
        $chunks = (new Chunker())->chunk("Widerruf\n\nBinnen vierzehn Tagen.");

        self::assertCount(1, $chunks);
        self::assertStringContainsString('vierzehn Tagen', self::at($chunks, 0)['text']);
    }

    public function testAParagraphIsNeverSplitMidSentenceWhenItFits(): void
    {
        $paragraph = 'Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gruenden zu widerrufen.';
        $text = implode("\n\n", array_fill(0, 40, $paragraph));

        foreach ((new Chunker())->chunk($text) as $chunk) {
            // Every chunk begins at a paragraph boundary, so it begins with a capital and ends with a
            // full stop. A character-count splitter fails this immediately — and so does a
            // character-granular overlap, which is why the overlap is whole paragraphs.
            self::assertMatchesRegularExpression('/^Sie haben/', $chunk['text']);
            self::assertStringEndsWith('.', trim($chunk['text']));
        }
    }

    public function testChunksOverlapSoAThoughtSpanningABoundaryIsStillRetrievable(): void
    {
        $paragraphs = [];

        for ($i = 1; $i <= 30; ++$i) {
            $paragraphs[] = \sprintf(
                'Absatz %02d mit genug Text, dass mehrere Absaetze nicht in einen Chunk passen.',
                $i,
            );
        }

        $chunks = (new Chunker())->chunk(implode("\n\n", $paragraphs));

        self::assertGreaterThan(1, \count($chunks));

        // The tail of one chunk reappears at the head of the next.
        $firstTail = substr(trim(self::at($chunks, 0)['text']), -60);
        self::assertStringContainsString($firstTail, self::at($chunks, 1)['text']);
    }

    public function testEveryChunkStaysWithinTheBound(): void
    {
        $text = implode("\n\n", array_fill(0, 100, str_repeat('Wort ', 40)));

        foreach ((new Chunker())->chunk($text) as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }

    /** A single paragraph longer than the bound must still be split, or it can never be indexed. */
    public function testAParagraphLongerThanTheBoundIsSplitAnyway(): void
    {
        $chunks = (new Chunker())->chunk(str_repeat('Wort ', Chunker::MAX_CHARS));

        self::assertGreaterThan(1, \count($chunks));

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }

    public function testTheNearestHeadingBecomesTheSectionLabel(): void
    {
        $chunks = (new Chunker())->chunk("Widerrufsfrist\n\nBinnen vierzehn Tagen ab Erhalt der Ware.");

        self::assertSame('Widerrufsfrist', self::at($chunks, 0)['section']);
    }

    public function testTextWithNoParagraphBreaksAtAllStillChunks(): void
    {
        $chunks = (new Chunker())->chunk(str_repeat('a', 5000));

        self::assertNotSame([], $chunks);

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }

    /**
     * A multi-byte character must never be cut in half by the last-resort hard split, because half a
     * character is invalid UTF-8 and the whole passage stops being indexable.
     */
    public function testTheHardSplitNeverCutsAMultiByteCharacterInHalf(): void
    {
        $chunks = (new Chunker())->chunk(str_repeat('ä', 4000));

        self::assertNotSame([], $chunks);

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
            self::assertSame($chunk['text'], mb_convert_encoding($chunk['text'], 'UTF-8', 'UTF-8'));
        }
    }

    /**
     * `assertNotNull()` does not narrow a nullable for the analyzer, so the lookup throws instead.
     *
     * @param list<array{section: string, text: string}> $chunks
     *
     * @return array{section: string, text: string}
     */
    private static function at(array $chunks, int $index): array
    {
        return $chunks[$index] ?? self::fail(\sprintf('expected a chunk at index %d', $index));
    }
}
