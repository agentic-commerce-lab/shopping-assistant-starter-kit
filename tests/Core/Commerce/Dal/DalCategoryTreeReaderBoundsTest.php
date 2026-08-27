<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTaxonomy;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCategoryTreeReader;

/**
 * `DalCategoryTreeReader::MAX_NODES` (40) has zero headroom against the seeded fashion shop's `Brand`
 * branch (also 40, {@see FashionSeedTaxonomy::BRANDS}) — the final review of the fashion-catalogue-seeder
 * feature measured this exact coincidence and flagged it.
 *
 * Not fixed as a behavioural change: `MAX_NODES` truncation is currently unreachable in production.
 * `DalCategoryTreeReader::read()` is called from exactly one place,
 * {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation::of()}, always with `$parentId = null` —
 * the navigation root, whose seeded child count (7: Women, Men, Kids, Brand, Season, Occasion, Gifts &
 * Novelty) is well under the bound. Nothing in this codebase currently reads a deeper level, so the
 * `Brand` branch's 40 children are never actually fetched through this reader. Raising `MAX_NODES`
 * speculatively, with no live consumer to measure against, would repeat the exact mistake its own
 * docblock already calls out — "a bound chosen for headroom rather than from data."
 *
 * This test is the lightweight alternative: it locks the coincidence in as deliberate rather than
 * accidental. If a future `browse_categories`-shaped feature starts reading non-root levels, or if either
 * constant changes, this goes red and forces a conscious decision instead of a silent truncation
 * discovered in production.
 */
final class DalCategoryTreeReaderBoundsTest extends TestCase
{
    public function testTheBrandBranchIsExactlyAtTheBoundNotOverIt(): void
    {
        $maxNodes = (new \ReflectionClassConstant(DalCategoryTreeReader::class, 'MAX_NODES'))->getValue();

        self::assertSame(
            $maxNodes,
            \count(FashionSeedTaxonomy::BRANDS),
            'DalCategoryTreeReader::MAX_NODES and the seeded Brand branch have drifted apart. '
            . 'If MAX_NODES grew: fine, more headroom. If the Brand branch grew past it: that branch '
            . 'would now silently truncate if anything ever reads it (still nothing does today — see '
            . 'this test\'s class docblock) — worth a deliberate look, not a silent pass.',
        );
    }
}
