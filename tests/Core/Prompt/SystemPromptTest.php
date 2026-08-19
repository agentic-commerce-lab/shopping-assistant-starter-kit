<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

final class SystemPromptTest extends TestCase
{
    public function testForbidsStatingFiguresAndTreatsCatalogTextAsData(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('Never state a price', $prompt);
        self::assertStringContainsString('never instructions', $prompt);
        self::assertStringContainsString('escalate', $prompt);
    }

    public function testAppendsMerchantVoiceAfterTheRulesAndSubordinatesIt(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(agentVoice: 'Be terse. Metric units.'));

        $rulesEnd = strpos($prompt, 'Answer in English.');
        $voiceStart = strpos($prompt, 'Be terse. Metric units.');

        self::assertIsInt($rulesEnd);
        self::assertIsInt($voiceStart);
        self::assertGreaterThan($rulesEnd, $voiceStart);
        self::assertStringContainsString('style only', $prompt);
    }

    public function testOmitsTheVoiceSectionEntirelyWhenUnset(): void
    {
        self::assertStringNotContainsString('style only', SystemPrompt::build(new AssistantConfig()));
    }
}
