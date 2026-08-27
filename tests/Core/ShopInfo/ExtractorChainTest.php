<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\DocxExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\HtmlExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\MarkdownExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PdfExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PlainExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;

/**
 * Turning a merchant's file into text, for the five formats spec decision R10 admits.
 *
 * The assertion that matters most is the last one: a PDF with no text layer — a scan — must FAIL, not
 * yield an empty document. An empty document indexes cleanly, matches nothing for ever, and gives the
 * merchant no reason to suspect anything.
 */
final class ExtractorChainTest extends TestCase
{
    private static function chain(): ExtractorChain
    {
        return new ExtractorChain([
            new PlainExtractor(),
            new MarkdownExtractor(),
            new HtmlExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
        ]);
    }

    public function testPlainTextPassesThrough(): void
    {
        self::assertSame('Widerrufsfrist: vierzehn Tage.', self::chain()
            ->extract('widerruf.txt', "Widerrufsfrist: vierzehn Tage.\n"));
    }

    public function testMarkdownLosesItsSyntaxButKeepsItsParagraphs(): void
    {
        $text = self::chain()->extract('faq.md', "# Widerruf\n\nBinnen **vierzehn** Tagen.\n");

        self::assertStringContainsString('Widerruf', $text);
        self::assertStringContainsString('Binnen vierzehn Tagen.', $text);
        self::assertStringNotContainsString('**', $text);
        self::assertStringNotContainsString('#', $text);
    }

    public function testHtmlBecomesOneLinePerBlockAndDropsScripts(): void
    {
        $text = self::chain()
            ->extract(
                'datenschutz.html',
                '<h1>Datenschutz</h1><p>Wir verarbeiten Daten.</p><script>evil()</script><ul><li>Auskunft</li></ul>',
            );

        self::assertSame("Datenschutz\nWir verarbeiten Daten.\nAuskunft", $text);
        self::assertStringNotContainsString('evil', $text);
    }

    public function testDocxParagraphsSurviveAndRunsAreJoined(): void
    {
        $text = self::chain()
            ->extract('widerruf.docx', self::docx([
                'Widerrufsbelehrung',
                // Three separate runs in one paragraph — Word splits sentences like this constantly, and
                // a naive extractor turns them into three lines. Verified while designing that this
                // approach joins them.
                ['Sie haben das Recht, binnen ', 'vierzehn Tagen', ' zu widerrufen.'],
            ]));

        self::assertSame("Widerrufsbelehrung\nSie haben das Recht, binnen vierzehn Tagen zu widerrufen.", $text);
    }

    public function testAnUnsupportedFormatIsRefusedByName(): void
    {
        $this->expectException(ExtractionFailed::class);
        $this->expectExceptionMessageMatches('/xlsx/');

        self::chain()->extract('groessen.xlsx', 'anything');
    }

    /**
     * A scan is the failure mode that would otherwise be silent: a valid PDF with no text layer.
     * It must throw, so the document lands in `failed` status with a reason the merchant can act on.
     */
    public function testAPdfWithoutATextLayerFailsRatherThanYieldingNothing(): void
    {
        $this->expectException(ExtractionFailed::class);

        self::chain()->extract('scan.pdf', self::pdfWithoutText());
    }

    /**
     * @param list<string|list<string>> $paragraphs a string is one run; a list is several runs in one
     *                                              paragraph
     */
    private static function docx(array $paragraphs): string
    {
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $runs = '';

            foreach (\is_array($paragraph) ? $paragraph : [$paragraph] as $run) {
                $runs .= '<w:r><w:t>' . htmlspecialchars($run, \ENT_XML1) . '</w:t></w:r>';
            }

            $body .= '<w:p>' . $runs . '</w:p>';
        }

        $xml =
            '<?xml version="1.0"?><w:document '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body
            . '</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'docx');
        self::assertIsString($path);

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function pdfWithoutText(): string
    {
        // dompdf ships with Shopware. An empty page produces a valid PDF whose content stream holds
        // no text-showing operator, which is what a scan looks like to a text parser.
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml('<div style="width:1px;height:1px"></div>');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
