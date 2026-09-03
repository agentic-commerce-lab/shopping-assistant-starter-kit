<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\ExplicitEmptyToolSchema;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Symfony\AI\Platform\Contract\Normalizer\ToolNormalizer;
use Symfony\AI\Platform\Tool\Tool;

/**
 * What an argument-less tool actually looks like on the wire.
 *
 * `go_to_checkout` is this plugin's first tool with no parameters, so this path had never run.
 * Measured before the fix: `JsonSchema\Factory::buildParameters()` returns `null` for a method with
 * no parameters and `ToolNormalizer` omits the key when it is null, so the function declaration went
 * out with no `parameters` at all. OpenAI accepts that; providers whose function spec carries
 * `parameters` as part of the object do not, and a rejected tool takes the whole turn down — on
 * those shops only.
 *
 * The assertions are on the encoded JSON rather than on the PHP array, because the defect this
 * guards is a shape difference that only exists after encoding: PHP's empty array encodes as `[]`
 * where the schema needs `{}`.
 */
final class ExplicitEmptyToolSchemaTest extends TestCase
{
    public function testAnArgumentLessToolDeclaresAnEmptyObjectSchema(): void
    {
        $json = self::wire(GoToCheckoutTool::class);

        self::assertStringContainsString('"parameters":{"type":"object","properties":{}}', $json);
        // Not `[]`: an array where the provider's schema requires an object is the same rejection
        // this class exists to prevent, one step further along.
        self::assertStringNotContainsString('"properties":[]', $json);
    }

    public function testAToolWithParametersIsPassedThroughUntouched(): void
    {
        // The reflection factory stays the source of truth. This decorator fills an absence; it
        // must never rewrite a schema that was derived from a real signature.
        $json = self::wire(EscalateTool::class);

        self::assertStringContainsString('"required":["reason"]', $json);
        self::assertStringContainsString('"additionalProperties":false', $json);
    }

    public function testTheNameAndDescriptionSurviveTheRebuild(): void
    {
        // The Tool is immutable, so filling the schema means constructing a new one — and dropping
        // the description would silently cost the model the only instruction telling it that it
        // cannot know the cart any other way.
        $json = self::wire(GoToCheckoutTool::class);

        self::assertStringContainsString('"name":"go_to_checkout"', $json);
        self::assertStringContainsString('the only way to find out', $json);
    }

    private static function wire(string $class): string
    {
        $tools = iterator_to_array((new ExplicitEmptyToolSchema())->getTool($class), false);

        self::assertCount(1, $tools);
        self::assertInstanceOf(Tool::class, $tools[0]);

        return json_encode(
            (new ToolNormalizer())->normalize($tools[0]),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );
    }
}
