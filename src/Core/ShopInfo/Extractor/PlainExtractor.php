<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo\Extractor;

use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

/** Plain text, which is already what everything else is trying to become. */
final readonly class PlainExtractor implements TextExtractor
{
    public function supports(string $extension): bool
    {
        return $extension === 'txt';
    }

    public function extract(string $bytes): string
    {
        return trim($bytes);
    }
}
