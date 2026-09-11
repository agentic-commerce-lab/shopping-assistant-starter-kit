<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bundle;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bundle\BundleSeedRun;
use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * What the seeder decides before it writes: which bundles are healthy, and why the others are not.
 *
 * Split out of {@see \Swag\AssistantStarterKit\Command\SeedBundlesCommand} because the command sat on
 * the complexity gate the moment the refusals were added — the standing constraints answer that with
 * a split — and because this is the part worth testing. A command's `execute()` needs a shop; the
 * decision does not.
 *
 * **It refuses the whole run rather than writing the healthy ones.** A half-seeded bundle set is the
 * state that is hardest to reason about afterwards: the shop looks configured, the assistant answers
 * about some bundles and not others, and nothing says which. Every problem reported here is one a
 * catalogue or a shop fixes in a minute.
 */
final class BundleSeedRunTest extends TestCase
{
    private const CHANNEL = '01a01edd80bc719a92184bed257121ce';

    /**
     * @param list<array{number: string, quantity: int, required: bool}> $items
     *
     * @return array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>}
     */
    private static function bundle(array $items, string $category = 'Maintenance'): array
    {
        return [
            'number' => 'bundle-test',
            'name' => 'Test Bundle',
            'description' => 'A set.',
            'category' => $category,
            'discount' => ['type' => 'percentage', 'value' => 10.0],
            'items' => $items,
        ];
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<array{number: string, quantity: int, required: bool}>
     */
    private static function items(array $numbers): array
    {
        $items = [];

        foreach ($numbers as $number) {
            $items[] = ['number' => $number, 'quantity' => 1, 'required' => true];
        }

        return $items;
    }

    /**
     * @param list<array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>}> $bundles
     * @param array<string, int> $childCounts
     * @param array<string, string> $categories
     *
     * @return array{rows: list<array{0: string, 1: string, 2: int, 3: string, 4: string}>, payloads: list<array<string, mixed>>}
     */
    private static function plan(
        array $bundles,
        array $childCounts,
        array $categories = ['Maintenance' => 'cat0'],
    ): array {
        $ids = [];

        foreach (array_keys($childCounts) as $number) {
            $ids[$number] = str_pad((string) \count($ids), 32, 'a');
        }

        return BundleSeedRun::plan(
            $bundles,
            ['ids' => $ids, 'childCounts' => $childCounts],
            $categories,
            new SeedTax('01a01edcb74671e8aaa09605e7ac44be', 19.0),
            self::CHANNEL,
        );
    }

    public function testAHealthyBundleBecomesOnePayloadAndReportsOk(): void
    {
        $plan = self::plan([self::bundle(self::items(['a', 'b']))], ['a' => 0, 'b' => 0]);

        self::assertCount(1, $plan['payloads']);
        self::assertSame('ok', $plan['rows'][0][4]);
    }

    public function testAMemberWithVariantsIsRefusedAndNamed(): void
    {
        // The silent-404 case: written happily, then invisible shop-wide.
        $plan = self::plan([self::bundle(self::items(['a', 'bk-chain']))], ['a' => 0, 'bk-chain' => 3]);

        self::assertSame([], $plan['payloads']);
        self::assertStringContainsString('bk-chain', (string) $plan['rows'][0][4]);
        self::assertStringContainsString('hides the whole bundle', (string) $plan['rows'][0][4]);
    }

    public function testAMemberTheShopDoesNotHaveIsRefusedAndNamed(): void
    {
        $plan = self::plan([self::bundle(self::items(['a', 'bk-ghost']))], ['a' => 0]);

        self::assertSame([], $plan['payloads']);
        self::assertStringContainsString('bk-ghost', (string) $plan['rows'][0][4]);
    }

    public function testAMissingCategoryIsRefusedAndNamed(): void
    {
        $plan = self::plan([self::bundle(self::items(['a']), 'Nowhere')], ['a' => 0]);

        self::assertSame([], $plan['payloads']);
        self::assertStringContainsString('Nowhere', (string) $plan['rows'][0][4]);
    }

    public function testEveryBundleIsReportedEvenWhenOneIsRefused(): void
    {
        // The table is the point of --dry-run: one bad bundle must not hide the state of the rest.
        $plan = self::plan([
            self::bundle(self::items(['a'])),
            self::bundle(self::items(['bk-chain'])),
        ], ['a' => 0, 'bk-chain' => 3]);

        self::assertCount(2, $plan['rows']);
        self::assertSame('ok', $plan['rows'][0][4]);
        self::assertNotSame('ok', $plan['rows'][1][4]);
    }
}
