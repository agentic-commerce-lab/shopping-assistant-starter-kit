<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\ShopInfo\InMemoryPassageStore;

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
        // Revised R3: the passages carry the relevance warning, because the model is what decides
        // whether any of them answers the question.
        self::assertSame(SearchShopInfoTool::RELEVANCE_NOTE, $result['note']);
    }

    /**
     * Below the recall floor there is nothing at all, and the model is told what that means.
     *
     * The floor no longer pretends to separate relevant from irrelevant — measurement showed no value
     * can (see the tool's own docblock). It discards what is plainly unrelated, and this is that case.
     */
    public function testNothingAboveTheRecallFloorYieldsANoteAndNoPassages(): void
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

    /**
     * The two notes must not be interchangeable.
     *
     * "Nothing was found" and "something was found that may be irrelevant" call for different
     * behaviour from the model, and a single note covering both would have to be vague about which
     * situation it is describing — at which point it stops steering anything.
     */
    public function testTheAbsenceNoteAndTheRelevanceNoteAreDifferentInstructions(): void
    {
        self::assertNotSame(SearchShopInfoTool::NO_MATCH_NOTE, SearchShopInfoTool::RELEVANCE_NOTE);

        // Both must forbid invention, because that is the failure they share.
        foreach ([SearchShopInfoTool::NO_MATCH_NOTE, SearchShopInfoTool::RELEVANCE_NOTE] as $note) {
            self::assertStringContainsString('erfinde', strtolower($note));
        }

        // Only the relevance note may say a passage might not belong; the absence note has no
        // passages to say it about.
        self::assertStringContainsString('may have nothing to do', SearchShopInfoTool::RELEVANCE_NOTE);
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
