<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The model-facing tool: the threshold, the reply shape, and the trace.
 *
 * No embedding call and no database — {@see LookupEmbedder} declares what is near and what is far,
 * which is the only way to test a threshold at all. See its docblock for what that does and does not
 * prove.
 */
final class SearchShopInfoToolTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const OTHER_CHANNEL = 'ffffffffffffffffffffffffffffffff';

    private const QUESTION = 'Wie lange kann ich zurueckschicken?';

    public function testAQuestionTheDocumentsAnswerReturnsPassages(): void
    {
        $result = self::tool()(self::QUESTION);

        $passage = $result['passages'][0] ?? self::fail('expected one passage');

        self::assertSame(1, $result['total']);
        self::assertStringContainsString('vierzehn Tagen', $passage['text']);
        self::assertSame('Widerrufsfrist', $passage['section']);
        self::assertArrayNotHasKey('note', $result);
    }

    /**
     * Spec R3, and the reason a threshold exists at all. Vector search always returns its nearest
     * neighbour, so without this the model receives a passage about payment data processing and
     * paraphrases it into an answer about Bitcoin.
     */
    public function testNothingAboveTheThresholdYieldsANoteAndNoPassages(): void
    {
        // Not registered with the embedder, so it embeds far from every stored passage.
        $result = self::tool()('Kann ich mit Bitcoin bezahlen?');

        self::assertSame([], $result['passages']);
        self::assertSame(0, $result['total']);
        self::assertArrayHasKey('note', $result);
        // The note must forbid the specific failure, not merely report absence — the precedent is
        // SearchProductsTool::NO_MATCH_NOTE.
        self::assertStringContainsString('erfinde', strtolower($result['note'] ?? self::fail('no note')));
    }

    public function testThePassageCountIsBounded(): void
    {
        $store = new InMemoryPassageStore();

        for ($i = 0; $i < (SearchShopInfoTool::MAX_PASSAGES + 3); ++$i) {
            $store->add(
                [new ShopInfoPassage('doc-' . $i, 'Widerrufsbelehrung', 'Widerrufsfrist', 'Absatz ' . $i)],
                [LookupEmbedder::NEAR],
                self::CHANNEL,
            );
        }

        $result = self::tool($store)(self::QUESTION);

        self::assertLessThanOrEqual(SearchShopInfoTool::MAX_PASSAGES, \count($result['passages']));
        self::assertSame(\count($result['passages']), $result['total']);
    }

    /** Spec R12: the tool queries with its own config's channel and no other. */
    public function testItOnlySeesItsOwnSalesChannel(): void
    {
        $result = self::tool(salesChannelId: self::OTHER_CHANNEL)(self::QUESTION);

        self::assertSame([], $result['passages']);
        self::assertArrayHasKey('note', $result);
    }

    /** Spec R5: every score is traced, including rejected ones, so the threshold is calibratable. */
    public function testEveryScoreIsTracedIncludingRejectedOnes(): void
    {
        $trace = new TraceRecorder();
        self::tool(trace: $trace)('Kann ich mit Bitcoin bezahlen?');

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertSame(0, $payload['accepted']);
        self::assertSame(SearchShopInfoTool::MIN_SCORE, $payload['threshold']);
        // The rejected score is the whole point: it is what says "we would have had the answer at
        // 0.62" after a week of real use, instead of that being guessed at now.
        self::assertNotSame([], $payload['scores']);
        self::assertArrayHasKey('ms', $payload);
    }

    public function testAnAcceptedPassageIsTracedWithItsScore(): void
    {
        $trace = new TraceRecorder();
        self::tool(trace: $trace)(self::QUESTION);

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertSame(1, $payload['accepted']);
        self::assertSame([1.0], $payload['scores']);
    }

    private static function tool(
        ?InMemoryPassageStore $store = null,
        ?TraceRecorder $trace = null,
        string $salesChannelId = self::CHANNEL,
    ): SearchShopInfoTool {
        if ($store === null) {
            $store = new InMemoryPassageStore();
            $store->add(
                [new ShopInfoPassage(
                    'doc-1',
                    'Widerrufsbelehrung',
                    'Widerrufsfrist',
                    'Sie haben das Recht, binnen vierzehn Tagen zu widerrufen.',
                )],
                [LookupEmbedder::NEAR],
                self::CHANNEL,
            );
        }

        return new SearchShopInfoTool(
            new LookupEmbedder([self::QUESTION => LookupEmbedder::NEAR]),
            $store,
            $trace ?? new TraceRecorder(),
            new AssistantConfig(salesChannelId: $salesChannelId, embeddingModel: 'text-embedding-3-small'),
        );
    }
}
