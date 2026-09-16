<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\InsightsDataScope;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

final class InsightsSettingsTest extends TestCase
{
    public function testItShipsDisabled(): void
    {
        // D25: it spends the merchant's money on every run. A feature that bills without being
        // asked is a defect, not a convenience.
        self::assertFalse((new InsightsSettings(llm: self::llm()))->enabled);
    }

    public function testTheDefaultScopeIsTheOneThatSendsNoShopperMessage(): void
    {
        self::assertSame(InsightsDataScope::Aggregates, (new InsightsSettings(llm: self::llm()))->dataScope);
    }

    public function testAnOutOfRangeSampleIsClampedRatherThanTrusted(): void
    {
        // A negative sample selects nothing and would read in the dashboard as "the judge found no
        // problems"; a sample above 100 claims a corpus larger than the night had. Both are lies a
        // chart would carry.
        self::assertSame(0, (new InsightsSettings(samplePercent: -5, llm: self::llm()))->samplePercent);
        self::assertSame(100, (new InsightsSettings(samplePercent: 500, llm: self::llm()))->samplePercent);
        self::assertSame(37, (new InsightsSettings(samplePercent: 37, llm: self::llm()))->samplePercent);
    }

    public function testAnUnknownStoredScopeFallsBackToTheNarrowOne(): void
    {
        // A typo in the database must not widen what leaves the shop.
        self::assertSame(InsightsDataScope::Aggregates, InsightsDataScope::fromStored('everything'));
        self::assertSame(InsightsDataScope::FullConversations, InsightsDataScope::fromStored('fullConversations'));
    }

    public function testTheJudgeFallsBackFieldByFieldRatherThanAsAWhole(): void
    {
        // A merchant may want the chat provider with a larger model. Falling back as a whole would
        // drop the one field they filled in and then bill them for the wrong model.
        $chat = new LlmSettings('https://openrouter.ai/api', 'chat-key', 'gemini-3.7-flash');

        $resolved = InsightsSettings::resolveLlm($chat, baseUrl: '', apiKey: '', model: 'gpt-5-mini');

        self::assertSame('https://openrouter.ai/api', $resolved->baseUrl);
        self::assertSame('chat-key', $resolved->apiKey);
        self::assertSame('gpt-5-mini', $resolved->model);
    }

    public function testAJudgeWithItsOwnProviderKeepsNothingFromTheChatModel(): void
    {
        $chat = new LlmSettings('https://openrouter.ai/api', 'chat-key', 'gemini-3.7-flash');

        $resolved = InsightsSettings::resolveLlm($chat, 'https://api.anthropic.test', 'judge-key', 'opus');

        self::assertSame('https://api.anthropic.test', $resolved->baseUrl);
        self::assertSame('judge-key', $resolved->apiKey);
        self::assertSame('opus', $resolved->model);
    }

    public function testAnUnconfiguredChatModelYieldsAModelNameRatherThanAnEmptyString(): void
    {
        // LlmSettings::$model is declared non-empty-string. An unconfigured shop must still produce
        // a valid value object here, because reading these settings must never throw — the
        // aggregation has to run even when no model exists anywhere.
        $resolved = InsightsSettings::resolveLlm(new LlmSettings('', '', 'none'), '', '', '');

        self::assertSame('none', $resolved->model);
    }

    private static function llm(): LlmSettings
    {
        return new LlmSettings('https://example.test', 'k', 'm');
    }
}
