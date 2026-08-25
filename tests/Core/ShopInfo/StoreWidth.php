<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

/**
 * @internal the width invariant {@see InMemoryPassageStore} mirrors from the real store
 *
 * Its own class for the same reason the real store split {@see \Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable}
 * off: width is a schema concern, and Mago's complexity budget is per class.
 */
final class StoreWidth
{
    /**
     * @param list<list<float>> $vectors
     *
     * @throws \RuntimeException when the incoming batch is a different width than the store holds
     */
    public static function guard(?int $held, array $vectors): void
    {
        $width = \count($vectors[0] ?? []);

        if ($held === null || $width === 0 || $held === $width) {
            return;
        }

        // A store must not hold two widths: it would answer from whichever half the query reached.
        throw new \RuntimeException(\sprintf(
            'The store holds %d-wide vectors but these are %d wide. The embedding model changed: '
            . 'index the documents again.',
            $held,
            $width,
        ));
    }
}
