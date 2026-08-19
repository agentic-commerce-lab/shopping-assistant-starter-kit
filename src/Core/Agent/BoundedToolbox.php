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
 * argument to some collaborator) is *also* a `\TypeError`, and must still
 * propagate rather than becoming a note the model shrugs off.
 * `getFile()`/`getLine()` cannot draw that line: confirmed empirically, BOTH
 * an argument-dispatch mismatch and one thrown from inside a tool's own body
 * report `getFile()`/`getLine()` pointing into the *tool's own file* — the
 * parameter's type declaration or the inner `throw` site respectively, never
 * `Toolbox`'s file either way. `getTrace()[0]` — the immediate calling frame
 * — does draw it: for an argument-dispatch mismatch, `getTrace()[0]['file']`
 * is the installed `Toolbox` class's own file (the `$tool->{$method}(...)`
 * call site itself), confirmed by reflecting `Toolbox::class`'s file rather
 * than hardcoding a vendor path; for a `\TypeError` raised inside a tool's
 * own body, `getTrace()[0]['file']` is wherever that inner call actually
 * lives, never `Toolbox`'s file. This is a structural comparison against a
 * resolved file path, not a message parse, so it carries no dependency on how
 * PHP happens to word its exception messages.
 *
 * A bad return type (a tool declaring `: array` but returning something else)
 * lands on the SAME side as an argument-dispatch mismatch under this check —
 * confirmed empirically — because PHP raises both violations from the same
 * engine-level call boundary: `getTrace()[0]` for a bad return type is also
 * `Toolbox`'s own file, the frame that invoked the tool method whose contract
 * it violated on the way out. That is the correct answer, not a gap: a wrong
 * return type is deterministic given the tool's code, never dependent on
 * runtime model input, so `composer run quality`'s own `mago analyze` step
 * (full type-checking, "≈ PHPStan max" per its config) already rejects it
 * statically, long before any live request — unlike an argument shape, which
 * only exists at runtime because the model supplies it. A bad return type
 * reaching this branch at all would mean the quality gate itself had already
 * failed.
 *
 * If `getTrace()` is empty or its first frame carries no `file` key, this
 * fails safe by treating the `\TypeError` as unrecognised: it falls through
 * to `throw $e;` below exactly as before this check existed, costing a turn
 * rather than ever risking the reverse — swallowing a real bug behind a
 * shrug-worthy note.
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
            // Null means "not bad model input" — a genuine server fault, which must
            // keep propagating rather than becoming a note the model shrugs off.
            return MalformedToolArgumentRejection::forToolExecutionException($this->trace, $toolCall, $e) ?? throw $e;
        } catch (SerializerExceptionInterface $e) {
            // Not reachable through the real vendor Toolbox today — it always wraps
            // this into a ToolExecutionException first, handled above — but kept as
            // its own disjoint catch in case a future denormalizer call site (or a
            // test double standing in for the inner toolbox) throws it unwrapped.
            return MalformedToolArgumentRejection::forUnwrappedSerializerException($this->trace, $toolCall, $e);
        }
    }
}
