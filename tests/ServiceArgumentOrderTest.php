<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every positional service argument must be assignable to the constructor parameter it lands on.
 *
 * ## The bug this exists for
 *
 * `DalDepartments` was added to {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCommerceGateway}
 * as the sixth constructor parameter, and its `<argument>` was appended to the END of that service's
 * definition. The count matched, every unit test passed — they construct the gateway themselves —
 * and the shop died on its first request with `Argument #6 ($departments) must be of type
 * DalDepartments, DalVariantFinder given`. Found 2026-09-14 only because that change was verified
 * against a running Shopware rather than against the suite.
 *
 * Positional arguments make this a permanent hazard: inserting a parameter anywhere but the end
 * silently shifts every argument after it, and nothing in PHP, PHPUnit or the analyzer looks at the
 * XML. This does.
 *
 * ## What it can and cannot check
 *
 * Only arguments whose service id is a class or interface this plugin can autoload. Core ids —
 * `sales_channel.product.repository`, `router`, `logger` — are names in Shopware's container with no
 * class to resolve here, and are skipped rather than guessed at. That still covers every argument
 * where the mistake is possible, because a shifted argument shifts OUR services too.
 *
 * Optional constructor parameters with defaults are covered the same way: a service that passes
 * fewer arguments than the constructor takes is fine, one that passes more is not.
 */
final class ServiceArgumentOrderTest extends TestCase
{
    private const SERVICES_XML = __DIR__ . '/../src/Resources/config/services.xml';

    /**
     * @return iterable<string, array{string, list<string>}> service class => its positional argument ids
     */
    public static function services(): iterable
    {
        foreach (ServiceDefinitions::withPositionalArguments(self::SERVICES_XML) as $class => $arguments) {
            yield $class => [$class, $arguments];
        }
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('services')]
    public function testEachArgumentMatchesTheParameterItLandsOn(string $class, array $arguments): void
    {
        self::assertTrue(class_exists($class), $class . ' is not autoloadable');

        /** @var class-string $class */
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor, \sprintf('%s takes arguments but has no constructor', $class));

        $parameters = $constructor->getParameters();

        self::assertLessThanOrEqual(
            \count($parameters),
            \count($arguments),
            \sprintf('%s is given more arguments than its constructor takes', $class),
        );

        foreach ($arguments as $position => $argumentId) {
            // A core container id — no class here to compare against, so nothing to assert.
            if (!class_exists($argumentId) && !interface_exists($argumentId)) {
                continue;
            }

            $parameter = $parameters[$position] ?? null;
            $expected = $parameter?->getType();

            if ($parameter === null || !$expected instanceof \ReflectionNamedType || $expected->isBuiltin()) {
                continue;
            }

            // Reflection rather than `is_a(..., allow_string: true)`: the class_exists guard above
            // proves this string names a type, but a static analyser cannot see that through the
            // negated pair, and reads the argument as a plain `string` where `class-string` is
            // required. ReflectionClass takes the string on its own terms, and `isSubclassOf`
            // answers for an implemented interface as well as a parent class.
            $expectedName = $expected->getName();

            self::assertTrue(
                $argumentId === $expectedName || (new \ReflectionClass($argumentId))->isSubclassOf($expectedName),
                \sprintf(
                    '%s argument #%d is %s but parameter $%s expects %s — a positional argument is '
                    . 'in the wrong place, which no unit test can see.',
                    $class,
                    $position + 1,
                    $argumentId,
                    $parameter->getName(),
                    $expectedName,
                ),
            );
        }
    }
}
