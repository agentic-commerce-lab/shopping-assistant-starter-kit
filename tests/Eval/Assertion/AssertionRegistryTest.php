<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\Assertion\AssertionRegistry;
use Swag\AssistantStarterKit\Eval\Assertion\NoInventedProduct;

/**
 * The registry resolves a journey's assertion names.
 *
 * Its shipped names are a closed vocabulary on purpose. What it must ALSO do is let a plugin that
 * contributed a grounded tool assert something about that tool: without it, an extension can be
 * tested by us and not by its author. A DI tag cannot serve that — journeys parse in a plain
 * PHPUnit process with no container — so the escape hatch is a class name.
 */
#[CoversClass(AssertionRegistry::class)]
final class AssertionRegistryTest extends TestCase
{
    public function testResolvesAShippedNameToItsAssertion(): void
    {
        self::assertInstanceOf(NoInventedProduct::class, AssertionRegistry::resolve(
            'no_invented_product',
            'some_journey',
        ));
    }

    public function testResolvesAThirdPartyAssertionByClassName(): void
    {
        $assertion = AssertionRegistry::resolve(ThirdPartyAssertion::class, 'some_journey');

        self::assertInstanceOf(ThirdPartyAssertion::class, $assertion);
        self::assertSame('acme_fitment_declared', $assertion->name());
    }

    public function testRejectsAClassThatIsNotAnAssertion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf(
            'does not implement %s',
            \Swag\AssistantStarterKit\Eval\Assertion::class,
        ));

        AssertionRegistry::resolve(\stdClass::class, 'some_journey');
    }

    public function testRejectsAnAssertionThatCannotBeConstructedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires constructor arguments');

        AssertionRegistry::resolve(ConstructorArgAssertion::class, 'some_journey');
    }

    public function testStillThrowsOnATypoedShippedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_invented_produtc');

        AssertionRegistry::resolve('no_invented_produtc', 'some_journey');
    }
}
