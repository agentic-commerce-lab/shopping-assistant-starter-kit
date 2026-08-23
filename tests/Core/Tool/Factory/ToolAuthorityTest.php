<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;

/**
 * The two-tier boundary, asserted structurally rather than trusted.
 *
 * `VISION.md`'s first non-negotiable is that the model is *structurally* incapable of inventing a
 * price. A contributed tool that could reach the gateway could hand the model a raw price and end
 * that, so an unprivileged tool must not be able to obtain one — not by convention, by type.
 */
final class ToolAuthorityTest extends TestCase
{
    public function testTheUnprivilegedContextExposesNothingBeyondTraceAndConfig(): void
    {
        // A property added here later is a hole opened here later. The assertion is deliberately
        // exhaustive so widening the unprivileged context cannot happen by accident.
        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(ToolContext::class))->getProperties(),
        );

        sort($properties);

        self::assertSame(['config', 'trace'], $properties);
    }

    public function testTheTwoContextsShareNoParent(): void
    {
        // If GroundedToolContext extended ToolContext, a factory typed against the unprivileged one
        // would receive a grounded instance at runtime and could downcast to it. Unrelated classes
        // make the boundary structural instead of advisory.
        self::assertFalse(is_subclass_of(GroundedToolContext::class, ToolContext::class));
        self::assertFalse(is_subclass_of(ToolContext::class, GroundedToolContext::class));
        self::assertSame([], class_parents(ToolContext::class));
        self::assertSame([], class_parents(GroundedToolContext::class));
    }

    public function testBothContextsAreFinalAndReadonly(): void
    {
        foreach ([ToolContext::class, GroundedToolContext::class] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), $class . ' must be final');
            self::assertTrue($reflection->isReadOnly(), $class . ' must be readonly');
        }
    }
}
