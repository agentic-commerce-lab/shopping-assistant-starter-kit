<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The match-reasons paragraph, in its own class rather than in {@see SystemPromptTest}: the same
 * reason this project already splits other config-specific test classes out (mago's method cap is a
 * fair reading of the alternative).
 *
 * Mirrors {@see SystemPromptTest::testThePromptStopsOrderingEscalationWhenTheToolIsGone()}'s own
 * mechanism: the paragraph is appended only when the capability it describes actually exists,
 * because an unconditional instruction to narrate reason codes the model will usually never receive
 * (enableMatchReasons defaults to false) is dead weight at best.
 */
final class SystemPromptMatchReasonsTest extends TestCase
{
    public function testOmitsTheMatchReasonsParagraphWhenTheFlagIsOff(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableMatchReasons: false));

        self::assertStringNotContainsString('reason codes', $prompt);
    }

    public function testIncludesTheMatchReasonsParagraphWhenTheFlagIsOn(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(enableMatchReasons: true));

        self::assertStringContainsString('reason codes', $prompt);
        self::assertStringContainsString('Never state a reason that was not given to you.', $prompt);
    }
}
