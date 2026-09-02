<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

/**
 * Split out of {@see BoundedToolbox} once the `\TypeError` handling this class exists
 * for pushed `BoundedToolbox`'s own cyclomatic-complexity total over this project's
 * threshold (mago sums it per class, across every method) — same reasoning as
 * {@see \Swag\AssistantStarterKit\Eval\Assertion\VariantStockCheck} being split out of
 * `StockMatchesSource`, including the static-method shape: this class holds no state of
 * its own, so `TraceRecorder` is a parameter on each call rather than an injected
 * collaborator.
 *
 * Decides which of `ToolExecutionException::getPrevious()`'s shapes represent bad model
 * input safe to convert into a retryable `['note' => …]` {@see ToolResult}, versus a
 * genuine server fault that must still propagate. See {@see BoundedToolbox}'s own
 * docblock for the full reasoning behind each of the three recognised shapes
 * ({@see ToolArgumentException}, a {@see SerializerExceptionInterface}, and an
 * argument-dispatch `\TypeError`) and why nothing else is treated this way.
 */
final class MalformedToolArgumentRejection
{
    /**
     * @return ToolResult|null null when `$e` represents a genuine server fault and must
     *                          propagate instead of being unwrapped
     */
    public static function forToolExecutionException(
        TraceRecorder $trace,
        ToolCall $toolCall,
        ToolExecutionException $e,
    ): ?ToolResult {
        $previous = $e->getPrevious();

        if ($previous instanceof ToolArgumentException) {
            $trace->record('tool.arguments.rejected', [
                'name' => $toolCall->getName(),
                'reason' => $previous->getMessage(),
            ]);

            return new ToolResult($toolCall, ['note' => self::retryable($previous->getMessage())]);
        }

        if ($previous instanceof SerializerExceptionInterface) {
            return self::reject($trace, $toolCall, $previous);
        }

        if ($previous instanceof \TypeError && self::isArgumentDispatchTypeError($previous)) {
            return self::reject($trace, $toolCall, $previous);
        }

        return null;
    }

    /**
     * Not reachable through the real vendor {@see Toolbox} today — it always wraps this
     * into a `ToolExecutionException` first, handled by {@see self::forToolExecutionException()}
     * — but kept for a toolbox that might throw it directly (or a test double standing in
     * for one).
     */
    public static function forUnwrappedSerializerException(
        TraceRecorder $trace,
        ToolCall $toolCall,
        SerializerExceptionInterface $e,
    ): ToolResult {
        return self::reject($trace, $toolCall, $e);
    }

    /**
     * True only for a `\TypeError` whose *immediate calling frame* — `getTrace()[0]`, not
     * `getFile()`/`getLine()` — sits inside the installed vendor `Toolbox` class's own
     * file: i.e. `Toolbox::execute()`'s `$tool->{$method}(...$arguments)` dispatch, not
     * some other call frames deep inside the tool's own body. See {@see BoundedToolbox}'s
     * class docblock for why `getFile()`/`getLine()` cannot make this distinction and
     * `getTrace()[0]` can, and how this fails safe.
     */
    private static function isArgumentDispatchTypeError(\TypeError $e): bool
    {
        try {
            $vendorDispatchFile = (new \ReflectionClass(Toolbox::class))->getFileName();
        } catch (\ReflectionException) {
            // `Toolbox` is a hard, always-installed dependency referenced by its own
            // class constant above, so reflection failing to find it cannot happen in
            // practice — but the same fail-safe posture as a missing trace frame below
            // applies here too: treat "cannot confirm the call site" as "not confirmed",
            // never as "assume it matches".
            return false;
        }

        if (false === $vendorDispatchFile) {
            return false;
        }

        $callingFrame = $e->getTrace()[0] ?? null;

        return \is_array($callingFrame) && ($callingFrame['file'] ?? null) === $vendorDispatchFile;
    }

    /**
     * A guard's own message, plus the one thing it never said: try again.
     *
     * **This branch was the only one without it**, and it is the one a model hits most often — it
     * carries the plugin's own semantic bounds, where {@see self::reject()} covers malformed types.
     * That sibling has told the model to "call it again" since it was written. Measured live with
     * `openai/gpt-5-mini` on 2026-09-02: rejected on `search_products`'s term bound, seven seconds
     * of reasoning, no second attempt, and a turn that rendered nothing. A stronger model infers
     * the retry from the bare sentence; a small one does not.
     *
     * **"once more", not "again".** An open invitation to retry spends `maxToolCallsPerTurn` on a
     * model that has already misread the schema, and the shopper pays for that in latency. One
     * corrected attempt is the whole intent.
     *
     * A guard message that already ends in its own instruction — `SearchTermList` now does — reads
     * fine with this appended, because the two say the same thing at different scopes: what to fix,
     * and that fixing it is worth doing.
     */
    private static function retryable(string $reason): string
    {
        return \sprintf('%s Correct the arguments and call the tool once more.', rtrim($reason));
    }

    private static function reject(
        TraceRecorder $trace,
        ToolCall $toolCall,
        SerializerExceptionInterface|\TypeError $e,
    ): ToolResult {
        $trace->record('tool.arguments.rejected', [
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
