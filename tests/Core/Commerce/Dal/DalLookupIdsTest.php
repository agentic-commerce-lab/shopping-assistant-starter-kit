<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalLookupIds;

/**
 * A product id the DAL cannot even parse is **not found**, not a dead turn.
 *
 * ## The turn this exists for
 *
 * Measured through the staging endpoint on 2026-09-10. Asked *"Compare the Merino Socks and the
 * Summer Socks 3-Pack"* with no prior search, the model had no ids and passed the names:
 *
 * ```
 * turn.failed: Execution of tool "compare_products" failed with error:
 *              Value is not a valid UUID: Merino Socks
 *   causedBy:  Shopware\Core\Framework\Uuid\Exception\InvalidUuidException
 * ```
 *
 * The shopper read *"Sorry — I could not finish that just now."* Nothing about the request was
 * unanswerable; the model simply named its arguments instead of identifying them.
 *
 * ## Why here and not in the tools
 *
 * `Guard` is where the tools reject malformed model arguments, and {@see \Swag\AssistantStarterKit\Core\Agent\BoundedToolbox}
 * already turns a `ToolArgumentException` into a retryable note — so that looks like the obvious
 * home. It is the wrong one: **being a UUID is a property of the DAL, not of the tool contract.**
 * `FixtureCommerceGateway` runs the whole eval and journey suite on ids like `fx-017` and `sk-101`,
 * and a UUID check at the tool boundary would reject every one of them.
 *
 * So the check sits at the gateway that actually requires it, and its answer is the one that
 * boundary already gives for an id it cannot resolve: nothing. `product()` returns null,
 * `products()` skips it, and all three id-taking tools reach their existing, tested
 * "No such product in this shop." path instead of raising.
 */
final class DalLookupIdsTest extends TestCase
{
    private const REAL = '01a08b4969f07207b0ea19c739d32685';

    public function testAProductNameIsNotAUsableId(): void
    {
        self::assertNull(DalLookupIds::one('Merino Socks'));
    }

    public function testAValidUuidIsUsableEvenWhenNoSuchProductExists(): void
    {
        // The distinction that keeps this a parse check and nothing more: whether a well-formed id
        // resolves to a product is the repository's answer, not this class's.
        self::assertSame(self::REAL, DalLookupIds::one(self::REAL));
    }

    public function testTheUnusableIdsAreDroppedAndTheRestSurvive(): void
    {
        // What `compare_products` hands over: a mix is the realistic case, and the ids that can be
        // looked up still should be.
        self::assertSame([self::REAL], DalLookupIds::usable([self::REAL, 'Merino Socks', '']));
    }

    public function testAListWithNothingUsableIsEmptyRatherThanRaising(): void
    {
        self::assertSame([], DalLookupIds::usable(['Merino Socks', 'Summer Socks 3-Pack']));
    }

    public function testTheListIsReindexedSoItStaysAList(): void
    {
        // `EqualsAnyFilter` and every `@return list<string>` downstream expect sequential keys;
        // a filtered array with a hole is a different type to the analyzer.
        self::assertSame([self::REAL], array_values(DalLookupIds::usable(['nope', self::REAL])));
    }
}
