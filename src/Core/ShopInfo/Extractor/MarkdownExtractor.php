<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo\Extractor;

use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

/**
 * Markdown with its syntax removed and its paragraphs kept.
 *
 * Deliberately not a Markdown library: the goal is readable prose for an embedding model, not
 * faithful rendering. Blank lines survive every rule below, because they are the paragraph
 * boundaries the chunker splits on.
 */
final readonly class MarkdownExtractor implements TextExtractor
{
    /**
     * Order matters: fenced code goes before inline code, and links before emphasis, so a `*` inside
     * a fence or a URL is never mistaken for markup.
     *
     * @var array<string, string>
     */
    private const RULES = [
        '/^```.*$/m' => '', // fence markers, keeping the code lines themselves
        '/`([^`]*)`/' => '$1', // inline code
        '/^\s{0,3}#{1,6}\s*/m' => '', // heading markers
        '/^\s{0,3}>\s?/m' => '', // block quotes
        '/!\[([^\]]*)\]\([^)]*\)/' => '$1', // images, keeping the alt text
        '/\[([^\]]*)\]\([^)]*\)/' => '$1', // links, keeping the label
        '/(\*\*|__)(.*?)\1/s' => '$2', // strong
        '/(\*|_)(.*?)\1/s' => '$2', // emphasis
        '/^\s{0,3}([-*+]|\d+\.)\s+/m' => '', // list markers
        '/^\s{0,3}([-*_])\s*(\1\s*){2,}$/m' => '', // thematic breaks
    ];

    public function supports(string $extension): bool
    {
        return \in_array($extension, ['md', 'markdown'], strict: true);
    }

    public function extract(string $bytes): string
    {
        $text = $bytes;

        foreach (self::RULES as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return trim($text);
    }
}
