<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Widens a whole number the model sent for a `float` parameter, before the framework's own
 * resolver denormalizes it.
 *
 * **The defect this exists for.** A shopper's budget is a whole number far more often than
 * not, and JSON has one number type: asked for "about 50 euros", a model emits
 * `{"priceMax": 50}`, not `50.0`. `ToolCallArgumentResolver` denormalizes each argument
 * against the tool method's declared type, and `Serializer::denormalize()` takes a scalar
 * shortcut for `float` that is a strict `is_float()` — so that call is rejected with
 * *"Data expected to be of type "float" ("int" given)"* before
 * {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} is ever entered, even though
 * the `?float $priceMax` parameter would have taken the integer happily under PHP's own
 * argument coercion.
 *
 * Measured on the local shop, 2026-08-31: **thirteen of sixteen** argument rejections in the
 * whole trace history were this one cause. In both turns of a live conversation where a
 * shopper stated a budget, the model retried the identical integer four and five times, then
 * dropped the argument and searched with no ceiling — and presented the results as though the
 * budget had been honoured. A shopper who says "50 euros" and is shown a 120-euro bag has been
 * told something untrue by a pipeline built to make that impossible.
 *
 * **Why here and not on the tool.** Relaxing the signature to `int|float` would change the
 * JSON Schema the model is shown, since `#[AsTool]` derives it from this exact signature by
 * reflection — trading a wrong rejection for a vaguer contract, and only for the one parameter
 * anybody remembered. This sits at the one seam every tool call already passes through, so it
 * holds for every float parameter any tool ever declares, contributed ones included.
 *
 * **Why only `int` to `float`, and in that direction only.** This is the one widening that
 * loses nothing: every PHP integer that reaches a `float` parameter would have been coerced to
 * exactly this value by the engine anyway, so nothing is decided here that PHP would have
 * decided differently. Everything else keeps the project's "reject, never coerce" rule intact —
 * a numeric string stays a string, and a fractional value sent for an `int` parameter
 * (`quantity: 2.5`) is still rejected rather than truncated, which is what makes the cart's
 * quantity bound worth trusting.
 */
final readonly class WholeNumberToolArguments implements ToolCallArgumentResolverInterface
{
    public function __construct(
        private ToolCallArgumentResolverInterface $inner = new ToolCallArgumentResolver(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolveArguments(Tool $metadata, ToolCall $toolCall): array
    {
        return $this->inner->resolveArguments($metadata, self::widened($metadata, $toolCall));
    }

    /**
     * The same {@see ToolCall} when nothing needed widening — a rebuilt one drops nothing today,
     * but the signature carried below is provider state we do not own, and not touching the
     * object at all is the version of that which cannot rot.
     */
    private static function widened(Tool $metadata, ToolCall $toolCall): ToolCall
    {
        $arguments = $toolCall->getArguments();
        $widened = false;

        foreach (self::floatParameters($metadata) as $name) {
            if (\array_key_exists($name, $arguments) && \is_int($arguments[$name])) {
                $arguments[$name] = (float) $arguments[$name];
                $widened = true;
            }
        }

        if (!$widened) {
            return $toolCall;
        }

        return new ToolCall(
            $toolCall->getId(),
            $toolCall->getName(),
            $arguments,
            // Carried, not dropped. Gemini scopes a signature to the tool call it guards and
            // rejects a replayed call that arrives without it, so losing it here would break
            // exactly the provider this project is most often run against — and only on the
            // turns where a shopper stated a budget, which is the hardest kind of bug to find.
            $toolCall->getSignature(),
        );
    }

    /**
     * The tool method's parameters declared `float` or `?float`, by name.
     *
     * A union type (`int|float`) is deliberately not included: it already accepts the integer,
     * so widening would only take a choice away from the tool that declared it.
     *
     * @return list<string>
     */
    private static function floatParameters(Tool $metadata): array
    {
        $class = $metadata->getReference()->getClass();

        if (!class_exists($class)) {
            // Same posture as the catch below, reached one step earlier: a reference naming a class
            // that is not loadable is not a signature this can read, and the framework's own
            // resolver will say so about the same reference a moment later, with the real message.
            return [];
        }

        try {
            $method = new \ReflectionMethod($class, $metadata->getReference()->getMethod());
        } catch (\ReflectionException) {
            // Unreachable through the real toolbox, which resolves the same reference a line
            // later and would throw there — so "cannot see the signature" means "widen nothing"
            // and let the framework report the real problem, rather than guessing at one here.
            return [];
        }

        $names = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && 'float' === $type->getName()) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }
}
