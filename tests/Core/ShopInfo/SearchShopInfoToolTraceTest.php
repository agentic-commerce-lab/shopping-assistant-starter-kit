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
 * What the tool writes to the trace, which is a contract and not a diagnostic.
 *
 * Two separate consumers depend on it. The admin trace view answers "why did it say that", and for a
 * document turn the passages *are* the answer. And
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unsupportedPeriods()} audits the reply
 * against those passages — so if this event stops carrying them, a safety control silently starts
 * reporting every stated period as invented.
 *
 * Split from {@see SearchShopInfoToolTest} because Mago's complexity budget is per class, and because
 * these are assertions about the trace rather than about the reply.
 */
final class SearchShopInfoToolTraceTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const QUESTION = 'Wie lange kann ich zurueckschicken?';

    /** Spec R5: every score is traced, including rejected ones, so the threshold is calibratable. */
    public function testEveryScoreIsTracedIncludingRejectedOnes(): void
    {
        $trace = new TraceRecorder();
        self::tool($trace)('Kann ich mit Bitcoin bezahlen?');

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertSame(0, $payload['accepted']);
        self::assertSame(SearchShopInfoTool::RECALL_MIN_SCORE, $payload['threshold']);
        // The rejected score is the whole point: it is what says "we would have had the answer at
        // 0.62" after a week of real use, instead of that being guessed at now.
        self::assertNotSame([], $payload['scores']);
        self::assertArrayHasKey('ms', $payload);
    }

    public function testAnAcceptedPassageIsTracedWithItsScore(): void
    {
        $trace = new TraceRecorder();
        self::tool($trace)(self::QUESTION);

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertSame(1, $payload['accepted']);
        self::assertSame([1.0], $payload['scores']);
    }

    /**
     * The passage text is traced, because it is what the audit compares the reply against.
     *
     * Without it `ProseAudit::unsupportedPeriods()` has no notion of what "supported" means and every
     * period a reply states would read as invented — and the admin trace view would show that a
     * retrieval happened without showing what it found.
     */
    public function testTheAcceptedPassageTextIsTracedSoTheReplyCanBeAudited(): void
    {
        $trace = new TraceRecorder();
        self::tool($trace)(self::QUESTION);

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertIsArray($payload['passages']);
        self::assertCount(1, $payload['passages']);
        self::assertStringContainsString('vierzehn Tagen', (string) ($payload['passages'][0] ?? ''));
    }

    /** Nothing accepted means nothing to audit against, and the key must still be present. */
    public function testRejectedPassagesAreNotTracedAsGivenToTheModel(): void
    {
        $trace = new TraceRecorder();
        self::tool($trace)('Kann ich mit Bitcoin bezahlen?');

        $payload = $trace->payload('retrieve.shopinfo') ?? self::fail('nothing recorded');

        self::assertSame([], $payload['passages']);
    }

    private static function tool(TraceRecorder $trace): SearchShopInfoTool
    {
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

        return new SearchShopInfoTool(
            new LookupEmbedder([self::QUESTION => LookupEmbedder::NEAR]),
            $store,
            $trace,
            new AssistantConfig(salesChannelId: self::CHANNEL, embeddingModel: 'text-embedding-3-small'),
        );
    }
}
