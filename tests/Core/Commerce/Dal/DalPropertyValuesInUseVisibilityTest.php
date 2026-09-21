<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalPropertyValuesInUse;

/**
 * The one guard this defect can have in a suite with no database.
 *
 * ## What went wrong
 *
 * Measured on staging 2026-09-21, the fast path returned **one** size where the aggregation it
 * claims to be equivalent to returned **ten**, and two colours against seven — 43 values against 66.
 * Every missing one was a variant option.
 *
 * `product_option` holds variant rows only, and a variant has no `product_visibility` row of its
 * own: visibility is written on the parent and inherited. Measured on the same shop, 166 variants
 * and **zero** with their own row. The option half of the query therefore matched nothing at all,
 * on every shop that sells variants, and the property half was the whole answer.
 *
 * The consequence was not a smaller list. `TermsFacetFinder` resolves a shopper's *"in 700x32"*
 * against these values; unmatched, the filter is DROPPED and the search runs unnarrowed. On a
 * catalogue of 1,355 dresses, "in size 38" stopped narrowing anything and the candidate window
 * filled with the wrong sizes. The same values are also the catalogue vocabulary in the system
 * prompt, so the model was told this shop sells one size.
 *
 * ## Why this is a string assertion
 *
 * The real check is the two paths agreeing, and that needs a database with variants in it. This
 * suite has none — 2,242 tests, all in memory, no `.env` in CI — so the equivalence is verified by
 * measurement against a real shop and recorded in {@see DalPropertyValuesInUse}'s docblock instead.
 *
 * What is left for an automated guard is the shape of the join, and it is worth having: the class
 * already carried a comment asserting equivalence that nobody had ever checked, and this is the
 * line that broke it.
 */
final class DalPropertyValuesInUseVisibilityTest extends TestCase
{
    private function source(): string
    {
        $file = (new \ReflectionClass(DalPropertyValuesInUse::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    public function testVisibilityIsLookedUpOnTheParentWhenTheRowIsAVariant(): void
    {
        self::assertStringContainsString('COALESCE(p.parent_id, p.id)', $this->source());
    }

    public function testActiveIsAlsoReadThroughTheParent(): void
    {
        // The second inherited column in the same WHERE clause, and the one that kept the query
        // empty after the visibility join was already fixed. Measured on the same shop: all 166
        // variants carry `active IS NULL`, so `p.active = 1` excluded every one of them — a fix to
        // visibility alone changed the result by exactly nothing.
        self::assertStringContainsString('COALESCE(p.active, parent.active) = 1', $this->source());
    }

    public function testNoVisibilityJoinMatchesTheRowsOwnIdAlone(): void
    {
        // The exact spelling that produced the defect. A later "simplification" back to it would
        // silently empty the option half again, and nothing else in this suite would notice.
        self::assertStringNotContainsString('ON v.product_id = p.id', $this->source());
    }
}
