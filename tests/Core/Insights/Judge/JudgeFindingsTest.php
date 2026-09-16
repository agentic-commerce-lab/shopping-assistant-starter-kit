<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Judge;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFindings;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFindingType;

final class JudgeFindingsTest extends TestCase
{
    public function testItKeepsAFindingWhoseQuoteReallyOccursInItsConversation(): void
    {
        $json = json_encode(
            [[
                'type' => 'wrong_or_missed_answer',
                'severity' => 'warning',
                'summary' => 'Claimed a resistance time the shop never published.',
                'quote' => 'often 10 minutes or more',
                'suggestion' => 'State that no cut-resistance rating is published.',
                'conversationId' => 'c1',
            ]],
            \JSON_THROW_ON_ERROR,
        );

        $findings = JudgeFindings::from($json, [self::trace('c1', 'It would take often 10 minutes or more.')]);

        self::assertCount(1, $findings);
        self::assertSame(JudgeFindingType::WrongOrMissedAnswer, $findings[0]->type ?? null);
    }

    public function testItDropsAFindingWhoseQuoteDoesNotOccur(): void
    {
        // The only defence against a judge inventing its evidence, and it is cheap. A finding a
        // merchant cannot verify against the transcript is worse than no finding.
        $json = json_encode(
            [[
                'type' => 'wrong_or_missed_answer',
                'severity' => 'warning',
                'summary' => 'Invented a certification.',
                'quote' => 'certified to EN 1078',
                'suggestion' => 'Nothing to do.',
                'conversationId' => 'c1',
            ]],
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame([], JudgeFindings::from($json, [self::trace('c1', 'A plain reply.')]));
    }

    public function testItDropsAnUnknownTypeRatherThanCoercingIt(): void
    {
        // D24: the type set is closed because only a fixed type can be counted week over week.
        // Coercing an unknown one to a known one would put a wrong label on a real chart.
        $json = json_encode(
            [[
                'type' => 'tone_problem',
                'severity' => 'warning',
                'summary' => 's',
                'quote' => 'A plain reply.',
                'suggestion' => 'x',
                'conversationId' => 'c1',
            ]],
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame([], JudgeFindings::from($json, [self::trace('c1', 'A plain reply.')]));
    }

    public function testItDropsAFindingAboutAConversationOutsideTheSample(): void
    {
        $json = json_encode(
            [[
                'type' => 'frustration',
                'severity' => 'info',
                'summary' => 's',
                'quote' => 'A plain reply.',
                'suggestion' => 'x',
                'conversationId' => 'c9',
            ]],
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame([], JudgeFindings::from($json, [self::trace('c1', 'A plain reply.')]));
    }

    public function testAnInjectionAttemptNeverCarriesASeverityAboveInfo(): void
    {
        // ARCHITECTURE.md: the dangerous tools are not implemented, "this is why prompt injection
        // has no payoff". Reporting an attempt is information; reporting it as risk is alarm about
        // something the architecture already neutralises.
        $json = json_encode(
            [[
                'type' => 'injection_attempt',
                'severity' => 'critical',
                'summary' => 'Someone tried to override the instructions.',
                'quote' => 'A plain reply.',
                'suggestion' => 'No action needed.',
                'conversationId' => 'c1',
            ]],
            \JSON_THROW_ON_ERROR,
        );

        $findings = JudgeFindings::from($json, [self::trace('c1', 'A plain reply.')]);

        self::assertSame('info', $findings[0]->severity ?? null);
    }

    public function testMalformedJsonThrowsSoTheRunCanRecordWhy(): void
    {
        $this->expectException(\JsonException::class);

        JudgeFindings::from('not json at all', [self::trace('c1', 'x')]);
    }

    private static function trace(string $id, string $prose): ConversationTrace
    {
        return new ConversationTrace(
            $id,
            new \DateTimeImmutable(),
            [],
            [
                ['role' => 'user', 'prose' => 'a question'],
                ['role' => 'assistant', 'prose' => $prose],
            ],
        );
    }
}
