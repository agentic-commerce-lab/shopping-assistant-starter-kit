<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;

/**
 * @internal an embedder whose output a test declares outright, so a test can say what is near and
 *           what is far
 *
 * **Why not {@see StubEmbedder} here.** That one is a character-frequency histogram, and two German
 * legal sentences have near-identical letter distributions — almost any pair scores above 0.9. It
 * cannot express "this question is unrelated to this passage", which is the entire subject of spec
 * R3's threshold. This one can, because the test writes the vectors.
 *
 * What that means for what these tests prove: they check the tool's *threshold logic* — that a
 * passage below the line yields a note and no passages, and one above yields the passage. Whether a
 * real model puts a real question above or below the line is a measurement against a real provider
 * (spec R4), not something a unit test can assert.
 */
final readonly class LookupEmbedder implements Embedder
{
    /** Orthogonal to {@see self::NEAR}, so cosine similarity is 0.0 — below any threshold. */
    public const FAR = [0.0, 1.0, 0.0, 0.0];

    /** Identical to the passage vectors the tests store, so cosine similarity is 1.0. */
    public const NEAR = [1.0, 0.0, 0.0, 0.0];

    /** @param array<string, list<float>> $vectors */
    public function __construct(
        private array $vectors = [],
    ) {}

    public function embed(array $texts): array
    {
        $vectors = [];

        foreach ($texts as $text) {
            // Unknown text is far: a test that forgets to register a question gets "no match", which
            // fails loudly in the assertion rather than passing for the wrong reason.
            $vectors[] = $this->vectors[$text] ?? self::FAR;
        }

        return $vectors;
    }
}
