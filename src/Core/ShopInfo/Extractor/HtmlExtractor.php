<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo\Extractor;

use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

/**
 * HTML as one line per block element.
 *
 * In v1 nobody uploads HTML. It is here because the CMS plan (spec R1) produces exactly HTML, so
 * building the path now means that plan inherits it instead of inventing a second one.
 *
 * `<script>` and `<style>` are removed rather than merely skipped: their text content is not prose,
 * and a stylesheet inlined into a passage would be embedded as if it were a sentence.
 */
final readonly class HtmlExtractor implements TextExtractor
{
    /**
     * Elements whose text becomes its own line.
     *
     * Only leaf-ish blocks, never `div` or `body`: a container's `textContent` is the concatenation
     * of everything inside it, so including containers would repeat the whole document as one line
     * before repeating it again piece by piece.
     */
    private const BLOCKS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'th', 'dd', 'dt'];

    private const STRIPPED = ['script', 'style'];

    public function supports(string $extension): bool
    {
        return \in_array($extension, ['html', 'htm'], strict: true);
    }

    public function extract(string $bytes): string
    {
        $dom = (new \Masterminds\HTML5())->loadHTML('<html><body>' . $bytes . '</body></html>');

        if (!$dom instanceof \DOMDocument) {
            throw ExtractionFailed::noText('html');
        }

        foreach (self::STRIPPED as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $lines = [];

        foreach ($dom->getElementsByTagName('*') as $node) {
            if (!\in_array(strtolower($node->nodeName), self::BLOCKS, strict: true)) {
                continue;
            }

            $line = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
