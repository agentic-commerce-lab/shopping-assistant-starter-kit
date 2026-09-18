<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\InsightJudge;
use Swag\AssistantStarterKit\Core\Insights\InsightMetrics;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Asks the configured model about a night's sample and validates what comes back.
 *
 * **A bare {@see Agent} with no tools and no processors.** The judge reads and reports; it has
 * nothing to look up, and giving it a toolbox would give it a way to be wrong about the shop as
 * well as about the conversations. This is also why it does not go through
 * `AssistantAgentFactory` — that builds the shopper-facing agent, with grounding processors whose
 * whole job is to police prose written for a shopper.
 *
 * **Everything the provider can throw becomes an {@see LlmException}.** The generator handles
 * exactly `JsonException` and `LlmException`, and a nightly task must not fall over on an HTTP
 * client's own exception class. Wrapping keeps "the judge had a bad night" a recorded reason rather
 * than an unhandled failure in a queue worker.
 *
 * A non-text result — a refusal, a tool call the model invented — yields an empty string, which
 * {@see JudgeFindings::from()} then rejects as malformed. That is the right outcome: the run
 * records that the judge did not answer, which is not the same as finding nothing.
 */
final readonly class JudgeRunner implements InsightJudge
{
    private const TEMPERATURE = 0.0;

    public function __construct(
        private LlmPlatformInterface $platform,
    ) {}

    /**
     * @param list<ConversationTrace> $traces
     *
     * @throws \JsonException when the answer is not a list of findings
     * @throws LlmException   when the provider cannot be reached or answers with an error
     *
     */
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings
    {
        $request = JudgeRequest::build($metrics, $traces, $settings->dataScope);

        $agent = new Agent($this->platform->of($settings->llm), $settings->llm->model);

        // `getResult()` is what actually runs the model: since Symfony AI 0.13 `call()` only hands
        // back a lazy execution, so resolving it outside this try would move every provider failure
        // out of the wrapping below and straight into the nightly task.
        try {
            $result = $agent->call(new MessageBag(Message::ofUser($request)), [
                'temperature' => self::TEMPERATURE,
            ])->getResult();
        } catch (\Throwable $failure) {
            throw new LlmException($failure->getMessage(), (int) $failure->getCode(), $failure);
        }

        return JudgeFindings::validate($result instanceof TextResult ? $result->getContent() : '', $traces);
    }
}
