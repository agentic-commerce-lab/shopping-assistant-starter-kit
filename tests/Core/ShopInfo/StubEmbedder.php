<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;

/**
 * @internal a deterministic embedder, so query ordering is reproducible and the suite makes no API
 *           call
 *
 * The vector is a character-frequency histogram of the text. **It carries no semantics**: two German
 * legal sentences have near-identical letter distributions, so almost any pair scores above 0.9. That
 * is fine for what this double is for — proving that a vector written is a vector retrieved, that the
 * tenant filter holds, and that a generation is replaced. It is deliberately NOT used to test the
 * similarity threshold: see {@see LookupEmbedder}, which lets a test say what is near and what is
 * far. Calibrating the real threshold is a measurement against a real model, not a unit test.
 */
final readonly class StubEmbedder implements Embedder
{
    public const WIDTH = 26;

    public function __construct(
        private int $width = self::WIDTH,
    ) {}

    public function embed(array $texts): array
    {
        $vectors = [];

        foreach ($texts as $text) {
            $vectors[] = self::vectorFor($text, $this->width);
        }

        return $vectors;
    }

    /** @return list<float> */
    public static function vectorFor(string $text, int $width = self::WIDTH): array
    {
        $vector = array_fill(0, max(1, $width), 0.0);

        foreach (str_split(strtolower($text)) as $character) {
            $index = \ord($character) % $width;
            $vector[$index] += 1.0;
        }

        return array_values($vector);
    }
}
