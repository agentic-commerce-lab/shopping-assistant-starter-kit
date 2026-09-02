<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;

/**
 * One name, several variants.
 *
 * Shopware names a variant after its parent, so a product name is not an identity: on an ordinary
 * turn several ids answer to it — the previous reply's card replayed by `RecentCardsContext`, the
 * product on screen, and whatever this turn's tools returned. Which one a name points at is decided
 * by the preferred list the caller passes, `FactRenderer::lastRetrievedBatch()`.
 *
 * Its own file rather than more methods on {@see ProseProductNamesTest}, which is at this project's
 * per-class method limit.
 */
final class ProseProductNamesAmbiguousNameTest extends TestCase
{
    /**
     * Every variant of a family carries its parent's name, so a name answers to several ids. The
     * preferred list — the turn's last tool batch — decides which, or a turn that added size L would
     * render the size M card the previous reply left behind. Reported live 2026-09-02.
     */
    public function testAnAmbiguousNameResolvesToThePreferredId(): void
    {
        $ids = ProseProductNames::idsNamedIn(
            'Added the Trail Jersey in size L.',
            [
                'id-jersey-m' => 'Trail Jersey',
                'id-jersey-l' => 'Trail Jersey',
            ],
            ['id-jersey-l'],
        );

        self::assertSame(['id-jersey-l'], $ids);
    }

    /** Registration order stays the tie-break for a name no preferred id answers to. */
    public function testAnAmbiguousNameFallsBackToRegistrationOrder(): void
    {
        $ids = ProseProductNames::idsNamedIn(
            'Added the Trail Jersey in size L.',
            [
                'id-jersey-m' => 'Trail Jersey',
                'id-jersey-l' => 'Trail Jersey',
            ],
            ['id-something-else'],
        );

        self::assertSame(['id-jersey-m'], $ids);
    }
}
