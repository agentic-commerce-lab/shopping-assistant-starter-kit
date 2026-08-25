<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Text into paragraphs, each labelled with the section it belongs to, none longer than the bound.
 *
 * Split from {@see Chunker} so that class is only about grouping: this one decides what a paragraph
 * is and which paragraphs are headings, while {@see BoundedParagraph} handles one too long to keep.
 * Keeping them apart is also what holds each inside Mago's per-class complexity budget.
 *
 * @phpstan-type Paragraph array{section: string, text: string}
 */
final readonly class ParagraphSplitter
{
    /**
     * A paragraph shorter than this with no sentence-ending punctuation is treated as a heading.
     *
     * Not a formatting rule — extraction has already thrown formatting away, so a heading is only
     * recognisable by shape. The consequence of guessing wrong is small in both directions: a short
     * sentence mislabelled as a heading becomes a section label, and a long heading simply does not.
     */
    private const HEADING_MAX_CHARS = 80;

    public function __construct(
        private int $maxChars,
    ) {}

    /**
     * @return list<Paragraph>
     */
    public function paragraphs(string $text): array
    {
        $blocks = preg_split('/\n\s*\n/', $text);

        if ($blocks === false) {
            return [];
        }

        $paragraphs = [];
        $section = '';

        foreach ($blocks as $block) {
            $block = trim((string) preg_replace('/\s*\n\s*/', ' ', trim($block)));

            if ($block === '') {
                continue;
            }

            if (self::isHeading($block)) {
                $section = $block;
            }

            foreach (BoundedParagraph::pieces($block, $this->maxChars) as $piece) {
                $paragraphs[] = ['section' => $section, 'text' => $piece];
            }
        }

        return $paragraphs;
    }

    private static function isHeading(string $block): bool
    {
        return \strlen($block) < self::HEADING_MAX_CHARS && preg_match('/[.!?]/', $block) !== 1;
    }
}
