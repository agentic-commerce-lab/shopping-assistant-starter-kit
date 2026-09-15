<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One document the shop has attached to a product — a datasheet, a manual, a safety data sheet.
 *
 * **A pointer, never a source of claims.** Nothing in this DTO carries the document's contents, and
 * that is the whole design of the first stage: the shop renders a link, the model says a document
 * exists, and no sentence about what the document *says* is licensed by anything here. Reading the
 * file is a separate piece of work with its own costs — measured 2026-09-11, a real safety data
 * sheet is 14–20k tokens and the shipped `smalot/pdfparser` cannot open it at all, because such
 * files are routinely encrypted with permission flags.
 *
 * That split is not a staging convenience. A safety data sheet is a regulatory document full of
 * hazard statements, and the system prompt already forbids the model to claim what a product is
 * rated or certified for unless the shop's own words make the claim. Surfacing the document without
 * reading it is the answer that rule asks for: point the shopper at the authority instead of
 * paraphrasing it.
 *
 * **`url` is for the shop to render, not for the model to read.** It never reaches
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}, for the same reason a price does
 * not: the prompt forbids the model to state a URL, and handing it one is an invitation to paste it
 * into prose where nothing can verify it.
 */
final readonly class ProductDocument
{
    public function __construct(
        public string $title,
        public string $url,
        public string $extension,
    ) {}
}
