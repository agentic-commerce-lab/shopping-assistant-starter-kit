<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bundle;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bundle\SingleVariantMembers;

/**
 * The constraint that makes a bundle vanish, checked before anything is written.
 *
 * **Measured on the staging shop, 2026-09-10, and it cost an hour.** A Drivetrain bundle was built
 * on `bk-chain` (3 variants) and `bk-quick-link-2pack` (2). Everything about the write succeeded:
 * the rows were there, `bundle_visibility` was populated, the derived stock and price were correct in
 * `cheapest_price_accessor`. And the bundle was **invisible shop-wide** — HTTP 404 on its own product
 * page, absent from every search, absent even from a bare `Criteria([$id])` on the sales-channel
 * repository. Searching its own name returned zero products.
 *
 * The cause is Commercial's `BundleAvailableFilter`, which excludes any bundle holding an item with
 * `childCount > 1` and no parent — its own comment says *"for first iteration, we need to filter out
 * non single variant items"*. Nothing reports it: there is no error, no log line and no admin
 * warning, so it presents as a broken plugin rather than as an unsupported configuration.
 *
 * Swapping both members for single-variant products made the bundle appear immediately, which is
 * what confirmed the cause rather than merely matching it.
 *
 * So the seeder refuses to write such a bundle and says which member disqualified it. A dry run
 * surfaces it before any write, which is the whole reason the bike seeder has one.
 */
final class SingleVariantMembersTest extends TestCase
{
    /**
     * @return list<array{number: string, quantity: int, required: bool}>
     */
    private static function items(string ...$numbers): array
    {
        $items = [];

        foreach ($numbers as $number) {
            $items[] = ['number' => $number, 'quantity' => 1, 'required' => true];
        }

        return $items;
    }

    public function testAFamilyParentDisqualifiesTheBundleThatContainsIt(): void
    {
        // bk-chain: childCount 3, no parent — the exact member that made a live bundle 404.
        $found = SingleVariantMembers::disqualifying(self::items('bk-lube-dry-100', 'bk-chain'), [
            'bk-lube-dry-100' => 0,
            'bk-chain' => 3,
        ]);

        self::assertSame(['bk-chain'], $found);
    }

    public function testEverySingleVariantMemberIsAccepted(): void
    {
        self::assertSame(
            [],
            SingleVariantMembers::disqualifying(self::items('bk-lube-dry-100', 'bk-tool-cassette-remover'), [
                'bk-lube-dry-100' => 0,
                'bk-tool-cassette-remover' => 0,
            ]),
        );
    }

    public function testEveryOffendingMemberIsNamedRatherThanJustTheFirst(): void
    {
        // Both were in the original hand-built bundle, and fixing one at a time is two round trips
        // against a shop where the symptom is a silent 404.
        self::assertSame(
            ['bk-chain', 'bk-quick-link-2pack'],
            SingleVariantMembers::disqualifying(self::items('bk-chain', 'bk-lube-dry-100', 'bk-quick-link-2pack'), [
                'bk-chain' => 3,
                'bk-lube-dry-100' => 0,
                'bk-quick-link-2pack' => 2,
            ]),
        );
    }

    /**
     * The filter's condition is `childCount > 1`, so a family with exactly one child does not trip
     * it. Encoded as measured rather than rounded to "has any children", because refusing a bundle
     * Commercial would have accepted is its own kind of wrong.
     */
    public function testAParentWithASingleChildIsNotExcludedByTheFilter(): void
    {
        self::assertSame([], SingleVariantMembers::disqualifying(self::items('bk-odd-one'), ['bk-odd-one' => 1]));
    }

    public function testAMemberTheShopDidNotResolveIsNotReportedHere(): void
    {
        // A missing product is a different failure with a different message — the command reports
        // unresolved numbers separately, and guessing a child count for one it never found would
        // turn "this product does not exist" into "this product has variants".
        self::assertSame([], SingleVariantMembers::disqualifying(self::items('bk-ghost'), []));
    }
}
