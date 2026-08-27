<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One file format, turned into plain text with its paragraph boundaries intact.
 *
 * Paragraphs matter more than they look: {@see Chunker} splits on them, and a chunk that begins
 * mid-sentence separates "binnen vierzehn Tagen" from "ab Erhalt der Ware" — a deadline without its
 * start date, which the model will then paraphrase as fact (spec R11).
 *
 * @api An extension point. A merchant with an in-house format implements this and tags the service
 *      `swag_assistant.text_extractor`; nothing else changes.
 */
interface TextExtractor
{
    public function supports(string $extension): bool;

    /**
     * @param string $bytes the file's raw contents
     *
     * @throws ExtractionFailed when the format is malformed or holds no extractable text
     */
    public function extract(string $bytes): string;
}
