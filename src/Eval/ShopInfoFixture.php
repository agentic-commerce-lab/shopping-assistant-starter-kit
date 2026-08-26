<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;
use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\PlatformEmbedder;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\InMemoryPassageStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One shop document, indexed into an in-process store, for a journey to retrieve from.
 *
 * **The document is chunked and embedded for real.** It goes through the shipped {@see Chunker} and a
 * real {@see PlatformEmbedder} against the configured provider, and the questions a journey asks are
 * embedded by the same model — so a journey exercises the whole chain rather than a rehearsal of it.
 *
 * The alternative was a hand-written fake embedder with vectors chosen so that one question lands near
 * a passage and another does not. That was rejected as circular: the interesting case under spec R3a
 * is the *near-miss* — a passage that scores above the recall floor while not answering the question —
 * and a fixture that manufactures near-misses is a fixture encoding the author's guess about which
 * questions are near-misses. Measuring that guess is the entire point.
 *
 * The cost is real API calls: the document's chunks once per attempt, plus one per question. Beside a
 * journey's model turns that is noise, and the eval suite already spends real money by design (S6).
 *
 * **No database.** The store is {@see InMemoryPassageStore}, which is why the eval suite keeps its
 * property of needing no Shopware and no MariaDB.
 */
final readonly class ShopInfoFixture
{
    /**
     * The sales channel every fixture passage is written for, and every fixture query filters on.
     *
     * A journey must run against the same channel or it retrieves nothing — which spec R12 makes a
     * feature, not a nuisance. {@see JourneyConfig} sets this on the config it builds, so the two
     * cannot disagree.
     */
    public const SALES_CHANNEL_ID = '01a01b4af6567284ac9eeb3616598ac3';

    private function __construct(
        public PassageStore $store,
        public Embedder $embedder,
    ) {}

    /**
     * @param string $documentPath a plain-text shop document
     *
     * @throws \RuntimeException when the file cannot be read or the provider cannot embed
     */
    public static function indexed(
        string $documentPath,
        LlmSettings $llm,
        string $embeddingModel,
        ?HttpClientInterface $http = null,
    ): self {
        $text = is_file($documentPath) ? file_get_contents($documentPath) : false;

        if ($text === false) {
            throw new \RuntimeException(\sprintf('No shop information fixture at "%s".', $documentPath));
        }

        $embedder = PlatformEmbedder::over($llm, $embeddingModel, $http);
        $chunks = (new Chunker())->chunk($text);
        $name = basename($documentPath);

        $passages = [];

        foreach ($chunks as $chunk) {
            $passages[] = new ShopInfoPassage($name, $name, $chunk['section'], $chunk['text']);
        }

        $store = new InMemoryPassageStore();
        $store->add(
            $passages,
            $embedder->embed(array_map(static fn(array $chunk): string => $chunk['text'], $chunks)),
            self::SALES_CHANNEL_ID,
        );

        return new self($store, $embedder);
    }
}
