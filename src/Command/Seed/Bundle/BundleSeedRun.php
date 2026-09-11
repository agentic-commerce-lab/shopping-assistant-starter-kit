<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bundle;

use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * Which bundles can be written, and what is wrong with the rest.
 *
 * Split out of {@see \Swag\AssistantStarterKit\Command\SeedBundlesCommand}, which sat on the
 * complexity gate as soon as the refusals were added — the standing constraints answer a complexity
 * finding with a split rather than a suppression. It is also the half worth testing: a command's
 * `execute()` needs a shop, this decision needs four arrays.
 *
 * **Every bundle is reported, healthy or not.** That table is the point of `--dry-run`: one bad
 * bundle must not hide the state of the others.
 *
 * The caller refuses the whole run if anything is wrong rather than writing the healthy ones. A
 * half-seeded set is the state hardest to reason about later — the shop looks configured, the
 * assistant answers about some bundles and not others, and nothing says which.
 */
final readonly class BundleSeedRun
{
    /**
     * @param list<array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>}> $bundles
     * @param array{ids: array<string, string>, childCounts: array<string, int>} $members
     * @param array<string, string> $categories category name => id
     *
     * @return array{rows: list<array{0: string, 1: string, 2: int, 3: string, 4: string}>, payloads: list<array<string, mixed>>}
     */
    public static function plan(
        array $bundles,
        array $members,
        array $categories,
        SeedTax $tax,
        string $salesChannelId,
    ): array {
        $rows = [];
        $payloads = [];

        foreach ($bundles as $bundle) {
            $problem = self::problemWith($bundle, $members, $categories);

            $rows[] = [
                $bundle['number'],
                $bundle['name'],
                \count($bundle['items']),
                $bundle['discount']['type'] . ' ' . $bundle['discount']['value'],
                $problem ?? 'ok',
            ];

            $categoryId = $categories[$bundle['category']] ?? null;

            // `$problem === null` already established the category resolves — see problemWith() —
            // but the null check is repeated rather than asserted so the guarantee is local.
            if ($problem === null && $categoryId !== null) {
                $payloads[] = BundleSeedPlan::payload($bundle, $members['ids'], $tax, $salesChannelId, $categoryId);
            }
        }

        return ['rows' => $rows, 'payloads' => $payloads];
    }

    /**
     * @param array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>} $bundle
     * @param array{ids: array<string, string>, childCounts: array<string, int>} $members
     * @param array<string, string> $categories
     */
    private static function problemWith(array $bundle, array $members, array $categories): ?string
    {
        $missing = array_values(array_filter(
            array_column($bundle['items'], 'number'),
            static fn(string $number): bool => !isset($members['ids'][$number]),
        ));

        if ($missing !== []) {
            return 'missing member(s): ' . implode(', ', $missing);
        }

        $families = SingleVariantMembers::disqualifying($bundle['items'], $members['childCounts']);

        if ($families !== []) {
            // The silent-404 case; see SingleVariantMembers for the measurement that found it.
            return 'member(s) have variants, which hides the whole bundle: ' . implode(', ', $families);
        }

        if (!isset($categories[$bundle['category']])) {
            return sprintf('category "%s" does not exist in this shop', $bundle['category']);
        }

        return null;
    }
}
