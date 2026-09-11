<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bundle;

/**
 * The members that would make a bundle invisible.
 *
 * **Commercial excludes a bundle containing a variant family, silently and completely.**
 * `BundleAvailableFilter` filters out any bundle with an item whose `childCount > 1` and whose
 * `parentId` is null — its own comment reads *"for first iteration, we need to filter out non single
 * variant items"*. Measured on staging 2026-09-10, a bundle built on `bk-chain` (3 variants) was
 * written without complaint, indexed correctly, priced correctly, and then returned **404 on its own
 * product page** and appeared in no search at all. There is no error, no log entry and no admin
 * warning, so it reads as a broken plugin rather than an unsupported configuration.
 *
 * Checking it here turns that into a sentence before anything is written. The threshold is the
 * filter's own — **`> 1`, not "has children"** — because refusing a bundle Commercial would have
 * accepted is its own kind of wrong.
 *
 * A member the shop did not resolve is **not** reported here: that is a different failure with a
 * different message, and inferring a child count for a product nobody found would turn "this product
 * does not exist" into "this product has variants".
 */
final readonly class SingleVariantMembers
{
    /**
     * @param list<array{number: string, quantity: int, required: bool}> $items
     * @param array<string, int> $childCounts product number => that product's `childCount`, for the
     *                                        members the shop actually resolved
     *
     * @return list<string> every offending member number, in the bundle's own item order — all of
     *                      them, because fixing one at a time is one round trip per member against
     *                      a shop whose only symptom is a silent 404
     */
    public static function disqualifying(array $items, array $childCounts): array
    {
        $offending = [];

        foreach ($items as $item) {
            $children = $childCounts[$item['number']] ?? null;

            if ($children !== null && $children > 1) {
                $offending[] = $item['number'];
            }
        }

        return $offending;
    }
}
