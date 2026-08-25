<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One retrieved piece of a shop document.
 *
 * `text` is the payload the model receives and may paraphrase (spec R6) — note the asymmetry with
 * products, where the model gets no figures because the rendered card carries them. There is no card
 * here, so the text is what there is.
 *
 * `score` is a **similarity** in `0.0..1.0`, higher being more similar. It is set by a query and
 * meaningless on a passage being written. It lives on this class rather than in a parallel array
 * because the threshold decision (R3) and the trace of rejected scores (R5) both need it beside the
 * passage it belongs to.
 */
final readonly class ShopInfoPassage
{
    public function __construct(
        public string $documentId,
        public string $documentName,
        public string $section,
        public string $text,
        public float $score = 0.0,
    ) {}
}
