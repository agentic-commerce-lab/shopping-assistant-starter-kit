<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

/**
 * The prose of a CMS page, gathered out of its slot configurations as HTML.
 *
 * **A pure function over plain arrays**, deliberately: spec R1 deferred CMS extraction because it is
 * the largest piece of this feature and has its own failure modes — text distributed across slots,
 * empty slots, slot types that hold no prose. Every one of those is a way to produce a document that
 * indexes cleanly and answers nothing, and none of them needs a database to test.
 *
 * The result is HTML rather than text, and goes through the same
 * {@see \Swag\AssistantStarterKit\Core\ShopInfo\Extractor\HtmlExtractor} an uploaded `.html` file
 * does. That is why HTML is in R10's format list although nobody uploads HTML: this path was always
 * going to need it, and building it then meant not inventing a second extractor now.
 */
final readonly class CmsPageText
{
    /**
     * The config keys that carry prose, in the order a slot is checked.
     *
     * `content` covers the `text` and `html` slot types, which is what a legal page is made of.
     * Anything else — an image's media id, a slider's product ids — is not prose and is skipped
     * rather than stringified into the document.
     *
     * @var list<string>
     */
    private const PROSE_KEYS = ['content'];

    /**
     * @param list<array{type?: string, config?: array<array-key, mixed>}> $slots in the order they
     *                                                                          appear on the page
     */
    public static function fromSlots(array $slots): string
    {
        $parts = [];

        foreach ($slots as $slot) {
            $prose = self::proseIn($slot['config'] ?? []);

            if ($prose !== '') {
                $parts[] = $prose;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function proseIn(array $config): string
    {
        foreach (self::PROSE_KEYS as $key) {
            $field = $config[$key] ?? null;

            if (!\is_array($field)) {
                continue;
            }

            $value = $field['value'] ?? null;

            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
