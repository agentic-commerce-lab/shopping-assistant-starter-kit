<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Judge;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\InsightsAggregator;
use Swag\AssistantStarterKit\Core\Insights\InsightsDataScope;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeRequest;

final class JudgeRequestTest extends TestCase
{
    public function testUnderTheNarrowScopeNoShopperMessageAppears(): void
    {
        // Acceptance criterion 6, asserted against the text that is actually sent rather than
        // against the builder's intent.
        $request = JudgeRequest::build(
            InsightsAggregator::aggregate([self::trace()]),
            [self::trace()],
            InsightsDataScope::Aggregates,
        );

        self::assertStringNotContainsString('wie lange haelt das schloss', $request);
        self::assertStringContainsString('It would take 10 minutes.', $request);
    }

    public function testUnderTheFullScopeTheShopperMessageIsIncluded(): void
    {
        $request = JudgeRequest::build(
            InsightsAggregator::aggregate([self::trace()]),
            [self::trace()],
            InsightsDataScope::FullConversations,
        );

        self::assertStringContainsString('wie lange haelt das schloss', $request);
    }

    public function testToolCallsAreAlwaysIncludedBecauseBadToolUseCannotBeJudgedWithoutThem(): void
    {
        $request = JudgeRequest::build(
            InsightsAggregator::aggregate([self::trace()]),
            [self::trace()],
            InsightsDataScope::Aggregates,
        );

        self::assertStringContainsString('search_products', $request);
    }

    public function testTheAggregatesTravelWithItSoTheJudgeHasFewerNumbersToInvent(): void
    {
        $request = JudgeRequest::build(
            InsightsAggregator::aggregate([self::trace()]),
            [self::trace()],
            InsightsDataScope::Aggregates,
        );

        self::assertStringContainsString('conversations', $request);
    }

    private static function trace(): ConversationTrace
    {
        return new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'tool.call', 'payload' => ['name' => 'search_products']],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
            ],
            [
                ['role' => 'user', 'prose' => 'wie lange haelt das schloss'],
                ['role' => 'assistant', 'prose' => 'It would take 10 minutes.'],
            ],
        );
    }
}
