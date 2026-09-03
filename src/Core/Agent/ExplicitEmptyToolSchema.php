<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Gives a tool that takes no arguments an explicit empty parameter schema.
 *
 * **Measured, not assumed.** `Factory::buildParameters()` returns `null` for a method with no
 * parameters, and `ToolNormalizer` omits the key entirely when it is null — so `go_to_checkout`,
 * this plugin's first argument-less tool, went over the wire as:
 *
 *     {"type":"function","function":{"name":"go_to_checkout","description":"…"}}
 *
 * OpenAI accepts that. It is not the only provider this runs against: the generic platform targets
 * arbitrary OpenAI-compatible endpoints, the eval journeys run on Gemini and GPT, and shops have
 * been configured against Mistral — whose function spec carries `parameters` as part of the object
 * rather than as an optional extra. A tool the provider rejects takes the whole turn down with it,
 * and it would do so only on the shops that use that provider, which is the hardest kind of defect
 * to see from here.
 *
 *     {"type":"function","function":{"name":"go_to_checkout","description":"…",
 *      "parameters":{"type":"object","properties":{}}}}
 *
 * is the form OpenAI's own documentation uses for a function with no arguments, so it is the widest
 * single answer available: it satisfies the providers that require the field and says exactly what
 * the omission meant to the ones that do not.
 *
 * **No `additionalProperties: false`.** It would be true and it is what the reflection factory emits
 * for tools that do have parameters — but strict-mode handling of an empty `properties` differs
 * between providers, and this class exists to stop guessing about provider behaviour rather than to
 * move the guess somewhere new. An argument-less tool has nothing to be strict about.
 *
 * Decorating the factory rather than the tool: `#[AsTool]` carries no schema override, so the only
 * place to say this once — for `go_to_checkout` and for whatever argument-less tool a shop
 * contributes next — is where {@see Tool} metadata is made.
 */
final readonly class ExplicitEmptyToolSchema implements ToolFactoryInterface
{
    public function __construct(
        private ToolFactoryInterface $inner = new ReflectionToolFactory(),
    ) {}

    public function getTool(object|string $reference): iterable
    {
        foreach ($this->inner->getTool($reference) as $tool) {
            yield $tool->getParameters() === null ? self::withEmptySchema($tool) : $tool;
        }
    }

    /**
     * The analyzer reads `Tool::$parameters` as the shape the reflection factory happens to produce
     * — properties keyed by name, a `required` list, `additionalProperties: false` — and an
     * argument-less tool has none of those. Satisfying that shape literally would mean writing
     * `'properties' => []`, which encodes as `[]` where the provider's schema requires `{}`: the
     * declared type and the wire format disagree here, and the wire is the one with a shop behind
     * it. Asserted end to end by `ExplicitEmptyToolSchemaTest`, on the encoded JSON.
     *
     * @mago-expect analysis:possibly-invalid-argument
     */
    private static function withEmptySchema(Tool $tool): Tool
    {
        return new Tool(
            $tool->getReference(),
            $tool->getName(),
            $tool->getDescription(),
            // `properties` is an empty object in JSON, and PHP's empty array encodes as `[]`. The
            // normalizer hands this straight to `json_encode`, so a plain `[]` would reach the
            // provider as an array where the schema requires an object — which is the same class of
            // rejection this class exists to prevent.
            ['type' => 'object', 'properties' => new \stdClass()],
            $tool->getMetadata(),
        );
    }
}
