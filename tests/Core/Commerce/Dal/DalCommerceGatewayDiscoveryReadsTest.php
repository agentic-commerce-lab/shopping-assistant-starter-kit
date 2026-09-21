<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCommerceGateway;

/**
 * Which gateway reads may hide a product, and which must never.
 *
 * ## Why this test reads source rather than behaviour
 *
 * {@see DalCommerceGateway} composes eight collaborators, six of them `final` and most needing a
 * live `Connection`, so it has no unit test and never had one — its behaviour is covered by the eval
 * suite and by live probes against a real shop. That leaves the one-word difference between
 * `build()` and `buildForDiscovery()` with nothing guarding it, and it is precisely the kind of
 * difference a later "why are there two of these?" cleanup deletes.
 *
 * The asymmetry it protects is not a detail. A discovery read may withhold a product the merchant
 * would rather not offer. A lookup by id, a batch lookup and a variant read must return it anyway,
 * or *"is the blue M still available?"* comes back as *"no such product"* — see
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters} for the whole argument.
 */
final class DalCommerceGatewayDiscoveryReadsTest extends TestCase
{
    /**
     * Reads one method's body out of the class file. Reflection gives the line span; the file
     * gives the text. No parser, because the assertion is about one method call being present.
     */
    private function bodyOf(string $method): string
    {
        $reflection = new \ReflectionMethod(DalCommerceGateway::class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);

        $lines = file($file);
        self::assertIsArray($lines);

        $start = $reflection->getStartLine() - 1;

        return implode('', \array_slice($lines, $start, $reflection->getEndLine() - $start));
    }

    /**
     * @return list<array{string}>
     */
    public static function discoveryReads(): array
    {
        // Everything that ANSWERS "what do you have?" — the search and the two counts beside it,
        // which have to describe the same set or the assistant advertises products it will not show.
        return [['search'], ['countMatches'], ['countMatchesUpTo']];
    }

    /**
     * @return list<array{string}>
     */
    public static function lookupReads(): array
    {
        // Everything that answers "tell me about THIS one", where the shopper already named it.
        return [['product'], ['products'], ['variantsOf']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('discoveryReads')]
    public function testAReadThatOffersProductsAsksForTheDiscoveryCriteria(string $method): void
    {
        self::assertStringContainsString('buildForDiscovery(', $this->bodyOf($method));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lookupReads')]
    public function testAReadAboutANamedProductNeverHidesIt(string $method): void
    {
        self::assertStringNotContainsString('buildForDiscovery(', $this->bodyOf($method));
    }
}
