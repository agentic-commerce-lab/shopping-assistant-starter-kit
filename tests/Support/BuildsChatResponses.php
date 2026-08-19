<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Builds `MockResponse` bodies shaped the way the installed Symfony AI 0.12 `Generic`
 * bridge expects, so a canned eval transcript reads as a short list of turns rather than
 * a wall of repeated nested arrays. Mirrors the shape already proven correct by
 * {@see \Swag\AssistantStarterKit\Tests\Core\Llm\PlatformFactoryTest} and
 * {@see \Swag\AssistantStarterKit\Tests\Eval\JourneyAttemptMultiTurnTest}:
 *
 * - `finish_reason` is required — the installed 0.12 `CompletionsConversionTrait` throws
 *   "Unsupported finish reason" without it.
 * - A tool call's `function.arguments` must be a JSON *string*, never an inline array.
 */
trait BuildsChatResponses
{
    private static function textResponse(string $content): MockResponse
    {
        return new MockResponse(json_encode(
            [
                'choices' => [[
                    'message' => ['content' => $content],
                    'finish_reason' => 'stop',
                ]],
            ],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string, mixed> $arguments */
    private static function toolCallResponse(
        string $toolName,
        array $arguments,
        string $callId = 'call-1',
    ): MockResponse {
        return new MockResponse(json_encode(
            [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => $callId,
                            'type' => 'function',
                            'function' => [
                                'name' => $toolName,
                                'arguments' => json_encode($arguments, \JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Builds the full HTTP call queue for several identical attempts by invoking
     * `$factory` fresh each time, so every attempt gets its own `MockResponse` instances
     * rather than sharing one mutable object across requests.
     *
     * @param callable(): list<MockResponse> $factory returns one attempt's full HTTP call sequence
     *
     * @return list<MockResponse>
     */
    private static function repeatSequence(callable $factory, int $times): array
    {
        $out = [];
        for ($i = 0; $i < $times; ++$i) {
            foreach ($factory() as $response) {
                $out[] = $response;
            }
        }

        return $out;
    }
}
