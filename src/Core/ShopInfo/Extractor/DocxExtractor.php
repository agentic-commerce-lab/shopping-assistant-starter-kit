<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo\Extractor;

use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

/**
 * DOCX with no new dependency: a docx is a zip, and `word/document.xml` is the body.
 *
 * **Known limitation, stated because it can matter.** This reaches body prose only — tables, headers,
 * footers and footnotes are dropped, and a footnote can carry substance in a legal text (a delivery
 * exception, a fee). `PhpOffice/PhpWord` would be complete, at the cost of a dependency for a case
 * nobody has measured (spec, *Known gaps*). If a merchant's revocation notice turns out to live in a
 * table, that is the moment to add it.
 */
final readonly class DocxExtractor implements TextExtractor
{
    public function supports(string $extension): bool
    {
        return $extension === 'docx';
    }

    public function extract(string $bytes): string
    {
        $xml = self::documentXml($bytes);

        // `</w:p>` ends a paragraph. Runs *inside* one paragraph must NOT become separate lines:
        // Word splits a single sentence across runs constantly (a changed font, a spell-check mark),
        // and one line per run would hand the chunker fragments instead of sentences.
        $withBreaks = (string) preg_replace('#</w:p>#', "\n", $xml);

        return trim(html_entity_decode(strip_tags($withBreaks), \ENT_QUOTES | \ENT_XML1));
    }

    /** @throws ExtractionFailed */
    private static function documentXml(string $bytes): string
    {
        // ZipArchive reads from a path, not a string, so the bytes have to land on disk first.
        $path = tempnam(sys_get_temp_dir(), 'swag-docx');

        if ($path === false) {
            throw ExtractionFailed::noText('docx');
        }

        try {
            file_put_contents($path, $bytes);

            $zip = new \ZipArchive();

            if ($zip->open($path) !== true) {
                throw ExtractionFailed::noText('docx');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            unlink($path);
        }

        if (!\is_string($xml)) {
            throw ExtractionFailed::noText('docx');
        }

        return $xml;
    }
}
