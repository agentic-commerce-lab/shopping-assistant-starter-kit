<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSummary;
use Swag\AssistantStarterKit\Tests\Support\BuildsConversationRows;

/**
 * The numbers in an exported file have to be the numbers on the screen. This is the one place they
 * are derived, so it is the one place that can make them disagree.
 */
final class TraceExportSummaryTest extends TestCase
{
    use BuildsConversationRows;

    public function testTheShopKeepsWhatItSpentAndTheModelTheRest(): void
    {
        // A real turn's offsets, measured 2026-08-24: the shop works up to 22ms, the model thinks
        // until 3634, the shop finishes at 3656. Model time is the gaps nothing was recorded in.
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(22, 'prompt'),
            self::event(3634, 'validate'),
            self::event(3656, 'turn.end'),
        ], totalMs: 3656), 'Storefront');

        self::assertSame(3656, $row['totalMs']);
        self::assertSame(3612, $row['modelMs'], 'the gap between prompt and validate is the model');
        self::assertSame(44, $row['shopMs'], 'everything else is the shop');
    }

    public function testAGapTooShortToBeARoundTripIsShopTime(): void
    {
        // 249ms is below the threshold phases.js measured — scheduling noise, not a model call.
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(249, 'turn.end'),
        ], totalMs: 249), 'Storefront');

        self::assertSame(0, $row['modelMs']);
        self::assertSame(249, $row['shopMs']);
    }

    public function testToolCallsAreCounted(): void
    {
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(10, 'tool.call'),
            self::event(20, 'retrieve'),
            self::event(30, 'tool.call'),
        ]), 'Storefront');

        self::assertSame(2, $row['toolCalls']);
    }

    public function testALoggedInShopperIsNamed(): void
    {
        $row = TraceExportSummary::of(self::conversation(customer: self::customer('Anna', 'Schmidt')), 'Storefront');

        self::assertSame('Anna Schmidt', $row['user']);
    }

    /**
     * Two states, and the second is not a fallback. `ON DELETE SET NULL` removes the id when a
     * customer deletes their account, so a conversation with no customer genuinely has none —
     * whether it never had one or no longer does.
     */
    public function testNoCustomerReadsAsGuest(): void
    {
        self::assertSame('Guest user', TraceExportSummary::of(self::conversation(), 'Storefront')['user']);
    }

    public function testAnEmptyTraceStillProducesARow(): void
    {
        // A conversation whose turn failed before any event was recorded is exactly the row a
        // merchant is looking for. It must not be the row that throws.
        $row = TraceExportSummary::of(self::conversation(events: []), 'Storefront');

        self::assertSame(0, $row['shopMs']);
        self::assertSame(0, $row['modelMs']);
        self::assertSame(0, $row['toolCalls']);
    }

    /**
     * The reviewer's ask, and the reason it is in the summary rather than only in the events: a file
     * full of turns that answered badly is unreadable without knowing which model answered them.
     */
    public function testTheModelThatAnsweredIsNamed(): void
    {
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(0, AssistantAgentFactory::MODEL_STAGE, ['name' => 'gpt-4o-mini']),
            self::event(12, 'turn.end'),
        ]), 'Storefront');

        self::assertSame(['gpt-4o-mini'], $row['models']);
    }

    /**
     * The model is a per-sales-channel setting, so a conversation can straddle a change to it. A
     * scalar here would have to name one of the two and be wrong about the other turn.
     */
    public function testAConversationThatStraddledAConfigChangeNamesBothModels(): void
    {
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(0, AssistantAgentFactory::MODEL_STAGE, ['name' => 'gpt-4o-mini']),
            self::event(12, 'turn.end'),
            self::event(20, AssistantAgentFactory::MODEL_STAGE, ['name' => 'gpt-4o-mini']),
            self::event(32, 'turn.end'),
            self::event(40, AssistantAgentFactory::MODEL_STAGE, ['name' => 'gpt-4o']),
            self::event(50, 'turn.end'),
        ]), 'Storefront');

        // Three turns, two models: named once each, in the order first seen.
        self::assertSame(['gpt-4o-mini', 'gpt-4o'], $row['models']);
    }

    /**
     * Rows written before the stage existed have no name, and the summary says so by saying nothing.
     * Retention keeps 30 days, so every shop upgrading into this has such rows on the day it does.
     */
    public function testAConversationRecordedBeforeTheStageExistedNamesNoModel(): void
    {
        $row = TraceExportSummary::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(12, 'turn.end'),
        ]), 'Storefront');

        self::assertSame([], $row['models']);
    }

    /**
     * The header order the CSV writer relies on: it writes `array_values()`, so a reordering here
     * silently moves every column under the wrong heading.
     */
    public function testTheKeyOrderIsTheColumnOrder(): void
    {
        self::assertSame(
            [
                'id',
                'createdAt',
                'salesChannel',
                'user',
                'turns',
                'outcome',
                'models',
                'totalMs',
                'shopMs',
                'modelMs',
                'toolCalls',
            ],
            array_keys(TraceExportSummary::of(self::conversation(), 'Storefront')),
        );
    }
}
