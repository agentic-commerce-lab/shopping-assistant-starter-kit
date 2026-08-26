<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Extraction failed, with a reason a merchant can act on.
 *
 * Named constructors rather than free-form messages because these two reasons need different
 * responses — one means "convert the file", the other means "this scan has no text in it, run OCR or
 * paste the text" — and the admin surface in Part 2 will want to tell them apart.
 */
final class ExtractionFailed extends \RuntimeException
{
    public static function unsupported(string $extension): self
    {
        return new self(\sprintf('No extractor handles ".%s". Supported: pdf, txt, md, html, docx.', $extension));
    }

    public static function noText(string $filename, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'No text could be extracted from "%s". A scanned PDF has no text layer — run OCR '
                . 'first, or upload the text itself.',
                $filename,
            ),
            previous: $previous,
        );
    }
}
