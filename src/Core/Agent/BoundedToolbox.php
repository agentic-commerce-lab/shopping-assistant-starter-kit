<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Toolbox;
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
 * A third shape reaches `$previous` the same way and for the same reason: a
 * native PHP `\TypeError` from `Toolbox::execute()`'s own
 * `$tool->{$method}(...$arguments)` call (vendor, line ~110) when a resolved
 * argument's runtime type still does not match the tool method's declared
 * parameter type — e.g. a plain `?array $options` parameter, which none of
 * the denormalizer's registered normalizers claim to support, so a
 * model-supplied scalar for it sails through denormalization unchanged and
 * only fails at the call itself. `\TypeError` does not implement
 * `ToolExecutionExceptionInterface` either, so this is wrapped into a
 * `ToolExecutionException` the exact same way, confirmed empirically the same
 * way as the Serializer case above.
 *
 * Unlike the other two shapes, `\TypeError` is not unique to this call site —
 * a genuine bug three calls deep inside a tool's own body (a wrong-typed
 * argument to some collaborator, a bad return type) is *also* a `\TypeError`,
 * and must still propagate rather than becoming a note the model shrugs off.
 * PHP's own argument-type-mismatch message carries a `"...called in %s on
 * line %d"` suffix naming the exact file and line of the *call expression*
 * that failed — not the tool's own file, where `getFile()`/`getLine()` point
 * instead (confirmed empirically: both an argument-spreading `\TypeError` and
 * one thrown from inside a tool body report `getFile()`/`getLine()` inside
 * the tool's own file; only the message text tells them apart). Matching that
 * suffix against the *installed* `Toolbox` class's own file, resolved via
 * reflection rather than a hardcoded vendor path, distinguishes "the engine
 * rejected this at our own dispatch call" from "the tool's own logic failed
 * three frames down" — confirmed empirically against all three shapes: an
 * argument-spread mismatch, a `\TypeError` thrown from a call inside a tool's
 * body, and a bad return type (which carries no `"called in"` suffix at all).
 * This is a message-format heuristic, not a type check, and PHP does not
 * document that suffix as a stable public contract — though it has been
 * unchanged across the whole 8.x line. If a future PHP version ever reworded
 * it, the regex simply stops matching and the exception falls through to
 * `throw $e;` below: failing to recognise a genuine argument-spread error
 * costs a turn, exactly as before this change, rather than ever risking the
 * reverse — swallowing a real bug behind a shrug-worthy note.
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

            if ($previous instanceof \TypeError && $this->isArgumentDispatchTypeError($previous)) {
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

    /**
     * True only for a `\TypeError` whose message names the installed vendor
     * `Toolbox` class's own file as the call site — i.e. `Toolbox::execute()`'s
     * `$tool->{$method}(...$arguments)` dispatch, not some other call three
     * frames deep inside the tool's own body. See the class docblock for why
     * this message-based check exists and how it fails safe.
     */
    private function isArgumentDispatchTypeError(\TypeError $e): bool
    {
        try {
            $vendorDispatchFile = (new \ReflectionClass(Toolbox::class))->getFileName();
        } catch (\ReflectionException) {
            // `Toolbox` is a hard, always-installed dependency referenced by its own
            // class constant above, so reflection failing to find it cannot happen in
            // practice — but the same fail-safe posture as an unresolvable file below
            // applies here too: treat "cannot confirm the call site" as "not confirmed",
            // never as "assume it matches".
            return false;
        }

        if (false === $vendorDispatchFile) {
            return false;
        }

        return 1 === preg_match(
            '/ called in ' . preg_quote($vendorDispatchFile, '/') . ' on line \d+$/',
            $e->getMessage(),
        );
    }

    private function rejectMalformedArguments(
        ToolCall $toolCall,
        SerializerExceptionInterface|\TypeError $e,
    ): ToolResult {
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
