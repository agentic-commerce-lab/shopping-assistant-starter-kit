<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Picks the extractor for a filename and guarantees the result is not empty.
 *
 * The empty check is here rather than in each extractor so no format can forget it. An empty document
 * is the dangerous outcome: it indexes without error, matches nothing for ever, and looks exactly
 * like a working upload.
 */
final readonly class ExtractorChain
{
    /** @param iterable<TextExtractor> $extractors */
    public function __construct(
        private iterable $extractors,
    ) {}

    /** @throws ExtractionFailed */
    public function extract(string $filename, string $bytes): string
    {
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($extension)) {
                continue;
            }

            $text = trim($extractor->extract($bytes));

            if ($text === '') {
                throw ExtractionFailed::noText($filename);
            }

            return $text;
        }

        throw ExtractionFailed::unsupported($extension);
    }
}
