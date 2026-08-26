<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Paragraphs grouped into overlapping chunks, each carrying the section it started in (spec R11).
 *
 * **Why paragraphs and not characters.** A character count splits "binnen vierzehn Tagen" from "ab
 * Erhalt der Ware", and the model then paraphrases a deadline without its start date — which reads
 * exactly like a fact. Paragraphs are the unit legal texts already come in.
 *
 * **The overlap is whole paragraphs, not a character window.** The plan proposed the last N
 * characters of the previous chunk; its own test forbids it, and the test is right. A character
 * window makes the next chunk *begin* mid-sentence, which reintroduces the failure the paragraph
 * split exists to prevent — the fragment is now at the start of a passage instead of the end of one.
 * So the overlap is as many trailing paragraphs as fit in {@see self::OVERLAP_CHARS}.
 *
 * @phpstan-import-type Paragraph from ParagraphSplitter
 */
final readonly class Chunker
{
    public const MAX_CHARS = 1200;

    public const OVERLAP_CHARS = 200;

    private const SEPARATOR = "\n\n";

    public function __construct(
        private ParagraphSplitter $splitter = new ParagraphSplitter(self::MAX_CHARS),
    ) {}

    /**
     * @return list<array{section: string, text: string}>
     */
    public function chunk(string $text): array
    {
        $chunks = [];
        /** @var list<string> $current */
        $current = [];
        $section = '';

        foreach ($this->splitter->paragraphs($text) as $paragraph) {
            if ($current !== [] && !self::fits($current, $paragraph['text'])) {
                $chunks[] = ['section' => $section, 'text' => implode(self::SEPARATOR, $current)];
                $current = self::overlapFor($current, $paragraph['text']);
                $section = $paragraph['section'];
            }

            if ($current === []) {
                $section = $paragraph['section'];
            }

            $current[] = $paragraph['text'];
        }

        if ($current !== []) {
            $chunks[] = ['section' => $section, 'text' => implode(self::SEPARATOR, $current)];
        }

        return $chunks;
    }

    /**
     * The trailing paragraphs of a finished chunk that should open the next one.
     *
     * Dropped entirely when carrying them would push the next chunk past the bound — which happens
     * only for paragraphs that were themselves force-split, and those are contiguous fragments of one
     * sentence, so an overlap would add nothing anyway.
     *
     * @param list<string> $current
     *
     * @return list<string>
     */
    private static function overlapFor(array $current, string $next): array
    {
        $overlap = [];

        foreach (array_reverse($current) as $paragraph) {
            $candidate = [$paragraph, ...$overlap];

            if (self::lengthOf($candidate) > self::OVERLAP_CHARS) {
                break;
            }

            $overlap = $candidate;
        }

        return self::fits($overlap, $next) ? $overlap : [];
    }

    /**
     * @param list<string> $current
     */
    private static function fits(array $current, string $next): bool
    {
        return self::lengthOf([...$current, $next]) <= self::MAX_CHARS;
    }

    /**
     * @param list<string> $paragraphs
     */
    private static function lengthOf(array $paragraphs): int
    {
        return \strlen(implode(self::SEPARATOR, $paragraphs));
    }
}
