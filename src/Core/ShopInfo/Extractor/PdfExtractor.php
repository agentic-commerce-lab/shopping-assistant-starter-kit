<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo\Extractor;

use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

/**
 * PDF via `smalot/pdfparser`.
 *
 * A scanned PDF has no text layer, and this returns nothing for it. {@see \Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain}
 * turns that nothing into a failure, which is the whole point: an empty document would index cleanly
 * and then never match anything, and the merchant would have no reason to look.
 */
final readonly class PdfExtractor implements TextExtractor
{
    public function supports(string $extension): bool
    {
        return $extension === 'pdf';
    }

    public function extract(string $bytes): string
    {
        try {
            return (new \Smalot\PdfParser\Parser())
                ->parseContent($bytes)
                ->getText();
        } catch (\Throwable $exception) {
            // A malformed PDF must not surface as a parser internal: the merchant sees this message,
            // and `$previous` keeps the real cause for the log.
            throw ExtractionFailed::noText('pdf', $exception);
        }
    }
}
