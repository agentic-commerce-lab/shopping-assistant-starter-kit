<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The trace stage a turn that died inside the agent leaves behind.
 *
 * **It had none.** {@see ShopwareChatTurnRunner::run()} caught `AgentExceptionInterface`, swapped in
 * a fixed apology and returned — recording nothing. Measured on a fresh 6.7.13.1 shop on 2026-09-02:
 * one turn in sixteen came back `outcome: error`, and its persisted trace stopped after `tool.call`
 * with no stage, no reason, and no log line anywhere in the shop. The comment on that catch said
 * *"the trace is what says where the turn stopped"*, and the trace was the one thing that did not
 * say it.
 *
 * A separate class rather than four lines in the catch, for the reason
 * {@see \Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTaskHandler} is a shell
 * over its pruner: the payload's shape is the part worth testing, and the runner it is called from
 * needs a whole agent to construct.
 *
 * **`turn.end` is deliberately NOT recorded here.** Ruling R40 makes a missing `turn.end` mean "the
 * turn never completed", and {@see \Swag\AssistantStarterKit\Eval\Assertion\CartContains} fails
 * loudly on its absence. Synthesising one to make the outcome look tidy would take that detection
 * away from every eval journey: the failure would then be indistinguishable from a turn that
 * completed and ended badly. Consumers that need an outcome read this stage instead —
 * {@see \Swag\AssistantStarterKit\Core\Trace\Sink\LoggerTraceSink} does.
 */
final class FailedTurn
{
    public const STAGE = 'turn.failed';

    /**
     * What a reader of this stage should treat the turn's outcome as. The same string
     * `AssistantTurn` is constructed with in the catch, so the log line and the conversation row
     * agree.
     */
    public const OUTCOME = 'error';

    /**
     * Bounded because this lands in a JSON column the Administration renders as one row of a
     * timeline. A provider that answers an error with a page of HTML must not put that page there.
     */
    public const MAX_MESSAGE_LENGTH = 200;

    /**
     * Runs one turn, degrading any failure into an apology and a trace stage.
     *
     * **Every throwable, and that is a correction.** The catch this replaces named
     * `Symfony\AI\Agent\Exception\ExceptionInterface` only — which reads as "model errors
     * degrade" and is not what it meant. Measured on 2026-09-02: pointing the model at a URL that
     * answers HTML instead of JSON threw
     * `Symfony\Component\HttpClient\Exception\JsonException`, an HTTP-client exception that
     * escaped the catch and took the storefront's chat endpoint to **HTTP 500** in front of the
     * shopper. Every provider incident page, proxy error and gateway timeout does the same, and
     * "degrade, never propagate" was the one promise this branch existed to keep.
     *
     * So the boundary is what the shopper can be shown, not which library raised it. An `Error` from
     * a contributed tool degrades too: a shopper must not see a stack trace because someone's plugin
     * has a type error, and {@see self::record()} names the class so it stays diagnosable.
     *
     * **Nothing is hidden from development.** The eval harness drives {@see AssistantRunner}
     * directly and never passes through here, so a broken tool still fails a journey loudly; and the
     * agent is *constructed* outside this call, so a misconfigured endpoint or a factory with the
     * wrong constructor signature still fails as loudly as it did before.
     *
     * @param \Closure(): AssistantTurn $run
     */
    public static function orDegrade(TraceRecorder $trace, string $language, \Closure $run): AssistantTurn
    {
        try {
            return $run();
        } catch (\Throwable $exception) {
            self::record($trace, $exception);

            return new AssistantTurn(FailedTurnMessage::for($language), [], self::OUTCOME);
        }
    }

    /**
     * **Class names and a bounded message, never the stack trace.** The trace is merchant-visible
     * (admin source, behind the module's ACL), and a stack trace carries argument values from a
     * request that quoted a shopper. The class plus its cause is what actually distinguishes a
     * provider timeout from a malformed tool call, which is the question this stage exists to
     * answer.
     */
    public static function record(TraceRecorder $trace, \Throwable $exception): void
    {
        $payload = [
            'exception' => $exception::class,
            'message' => self::readable($exception->getMessage()),
        ];

        $cause = $exception->getPrevious();

        // Named rather than flattened into the message: with a wrapped transport error the cause is
        // the useful half, and AGENTS.md asks for the exception together with its `getPrevious()`.
        if ($cause !== null) {
            $payload['causedBy'] = $cause::class;
        }

        $trace->record(self::STAGE, $payload);
    }

    private static function readable(string $message): string
    {
        $flattened = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($flattened === '') {
            // An empty string in the timeline reads as "nothing was recorded", which is the very
            // failure this class exists to end. The exception class is the diagnosis in this case.
            return '(no message)';
        }

        return mb_substr($flattened, 0, self::MAX_MESSAGE_LENGTH);
    }
}
