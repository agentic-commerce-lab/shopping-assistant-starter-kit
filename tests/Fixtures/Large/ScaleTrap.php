<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * The four engineered defects of the large catalogue, each aimed at one constant this kit sets
 * against seventeen sellable units.
 *
 * They are constants rather than literals because three places name each one — the generator that
 * builds it, the test that checks it was built, and the journey that asserts the assistant handles
 * it — and a typo in any of the three is otherwise a silently passing eval.
 *
 * See the spec's *Traps* table for what each one is aimed at and what a wrong answer looks like.
 */
final class ScaleTrap
{
    /** A 30-variant family: past `SearchProductsTool::MIN_CANDIDATES` of 20. */
    public const FAMILY_PARENT = 'sc-family-30';

    /**
     * The sold-out member of that family, generated **last** so retrieval ranking has to reach the
     * end of the window to find it. `SearchProductsTool`'s limit docblock records this exact failure
     * happening once already at `limit: 1`.
     */
    public const FAMILY_SOLD_OUT_VARIANT = 'sc-family-30-v30';

    /** Carries an option value the facet aggregation's 50-bucket cap cannot return. */
    public const RARE_OPTION_PRODUCT = 'sc-rare-option';

    public const RARE_OPTION_GROUP = 'Colour';

    /**
     * Deliberately a value the small fixture already uses in a different sense: ruling notes on
     * `UnmatchedOptionRetry` record `Chartreuse` as the "value in a group the catalogue has, that no
     * product carries" case. Here one product *does* carry it, and the question is whether the probe
     * can see it.
     */
    public const RARE_OPTION_VALUE = 'Chartreuse';

    /** A near-duplicate of `COMMON_NAME`, generated late so it sits deep in insertion order. */
    public const DEEP_DUPLICATE = 'sc-deep-duplicate';

    /** The name it duplicates — one of the small fixture's own near-duplicate pair. */
    public const COMMON_NAME = 'Alloy Water Bottle 750ml';

    /** A coined word shared by roughly 500 generated products, so no real word is polluted. */
    public const BROAD_TERM_WORD = 'Trailmaster';

    private function __construct() {}
}
