<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

/**
 * Decorates the framework's {@see \Symfony\AI\Agent\Toolbox\Toolbox} with two
 * guarantees `AgentProcessor` cannot provide on its own, both closed here in one
 * place because they share the same seam: every tool call passes through
 * `execute()` exactly once, regardless of how many tool-calling rounds a turn
 * takes.
 *
 * **1. `maxToolCallsPerTurn` is actually bounded.** `AgentProcessor::handleToolCallsCallback()`
 * declares its own `$iterations` counter as a *local variable* and resolves each
 * round by recursively re-invoking `Agent::call()`, which re-enters this same
 * processor's `processOutput()` and therefore `handleToolCallsCallback()` again —
 * with a *fresh* `$iterations = 0`. Every recursion level's own do-while loop
 * exits after exactly one pass once the nested call already returned a fully
 * resolved result, so `$iterations` never exceeds 1 at any level no matter how
 * many tool rounds the whole turn took. `AgentProcessor`'s own `maxToolCalls`
 * constructor argument is therefore inert; it is still passed for whatever
 * single-round protection it happens to offer, but this class is the actual
 * bound. Counting here works because this object is constructed once per
 * request and handed to `AgentProcessor` as `$toolbox`, so `$calls` survives
 * every recursion level untouched.
 *
 * **2. A tool argument mistake never aborts the whole turn.** `Toolbox::execute()`
 * wraps any `\Throwable` its tool throws — including our own
 * {@see ToolArgumentException} from {@see \Swag\AssistantStarterKit\Core\Tool\Guard} —
 * into a `ToolExecutionException`, which propagates uncaught through
 * `AgentProcessor` and `AssistantRunner::run()` and kills the request. An
 * oversized `term`, a malformed `options` entry or an out-of-range `quantity`
 * are the *most likely* model mistakes, not adversarial ones, and "reject,
 * never coerce" is only safe for the shopper if the model gets to see the
 * rejection and retry. This class unwraps exactly that case and hands the
 * model a normal `['note' => …]` tool result instead.
 *
 * The same unwrapping applies to a malformed argument *shape*, not just a
 * value our own {@see \Swag\AssistantStarterKit\Core\Tool\Guard} rejects.
 * Symfony AI denormalizes each tool-call argument through the Symfony
 * Serializer *before* our tool code ever runs, and a shape mismatch there
 * (an invalid backed-enum value, an unparsable date string, and so on) throws
 * a {@see SerializerExceptionInterface}, e.g.
 * {@see \Symfony\Component\Serializer\Exception\NotNormalizableValueException}.
 * That exception does not implement `ToolExecutionExceptionInterface` either,
 * so the vendor `Toolbox::execute()` wraps it into the very same
 * `ToolExecutionException` as any other tool failure — confirmed by reading
 * the installed `vendor/symfony/ai-agent/src/Toolbox/Toolbox.php` and
 * reproducing the wrap with a throwaway tool, since the framework's own
 * `@throws` docblocks do not mention it. It therefore surfaces here as
 * `$previous` on the exact same caught `ToolExecutionException`, not as a
 * raw exception of its own, and is unwrapped in the same catch block rather
 * than a truly independent one.
 *
 * A denormalization failure is always bad model input, safe to convert into
 * feedback the model can act on. A failure from inside the tool body that is
 * neither of these two recognised shapes may be a genuine server fault, so it
 * still propagates rather than being swallowed behind a note the model shrugs
 * off.
 *
 * Also records a uniform `tool.call` trace event at entry to every tool call —
 * `stage: 'dispatch'` — so the trace can answer "which tools ran" regardless of
 * which tool it was. Before this class, only {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool}
 * recorded `tool.call`, and it still does: its own event carries a policy
 * verdict and is recorded *after* the call resolves, under the same stage name
 * but without a `stage` key, so the two are never confused with each other in
 * the trace.
 */
final class BoundedToolbox implements ToolboxInterface
{
    private int $calls = 0;

    public function __construct(
        private readonly ToolboxInterface $inner,
        private readonly int $maxToolCallsPerTurn,
        private readonly TraceRecorder $trace,
    ) {}

    /** @return Tool[] */
    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->trace->record('tool.call', [
            'stage' => 'dispatch',
            'name' => $toolCall->getName(),
        ]);

        if (++$this->calls > $this->maxToolCallsPerTurn) {
            throw new MaxIterationsExceededException($this->maxToolCallsPerTurn);
        }

        try {
            return $this->inner->execute($toolCall);
        } catch (ToolExecutionException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof ToolArgumentException) {
                return new ToolResult($toolCall, ['note' => $previous->getMessage()]);
            }

            if ($previous instanceof SerializerExceptionInterface) {
                return $this->rejectMalformedArguments($toolCall, $previous);
            }

            throw $e;
        } catch (SerializerExceptionInterface $e) {
            // Not reachable through the real vendor Toolbox today — it always wraps
            // this into a ToolExecutionException first, handled above — but kept as
            // its own disjoint catch in case a future denormalizer call site (or a
            // test double standing in for the inner toolbox) throws it unwrapped.
            return $this->rejectMalformedArguments($toolCall, $e);
        }
    }

    private function rejectMalformedArguments(ToolCall $toolCall, SerializerExceptionInterface $e): ToolResult
    {
        $this->trace->record('tool.arguments.rejected', [
            'name' => $toolCall->getName(),
            'reason' => $e->getMessage(),
        ]);

        return new ToolResult($toolCall, ['note' => \sprintf(
            'One or more arguments were not in the expected shape: %s. '
            . 'Check the parameter types in the tool definition and call it again.',
            $e->getMessage(),
        )]);
    }
}
