<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Whether one sellable unit matches every given {@see VariantSelection}, and — because the answer
 * must not depend on which gateway is asking — the single implementation both of them use.
 *
 * {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} cannot tell a
 * {@see FixtureCommerceGateway} from a {@see Dal\DalCommerceGateway}, and its docblock states the
 * contract it trusts completely: *"it never guesses — it returns null unless the selections narrow
 * to exactly one variant"*. Two separate matchers would let the fixture suite prove that contract
 * while the real shop quietly implemented a different one. This class was extracted from
 * `FixtureVariantMatcher` when the DAL gateway needed the same logic, rather than copied.
 *
 * **Matching is case-sensitive, deliberately, for both the group and the value.** That looks like
 * a defect and is not:
 *
 * - Option **values** are already canonicalised upstream. `QueryBuilder`'s
 *   `VariantSelectionFilterResolver` re-spells each selection with the catalogue's own casing
 *   before `VariantResolver` ever sees it (ruling R20), so on the search path the strings match
 *   exactly by construction.
 * - **Group** names stay case-sensitive on purpose (ruling R21): `FacetSet::has()` is exact-match,
 *   so a model guessing "colour" for "Colour" loses the constraint **visibly** — it lands in
 *   `filtersDropped` where a merchant can see it. Case-insensitive group matching would also risk
 *   mis-targeting a different group.
 *
 * The one path that still hands over raw model casing is `GetProductTool` (ruling R46, parked). Its
 * fix belongs there, where the selections are built, and **not here** — loosening this comparison
 * would repair that symptom by discarding R21's visibility guarantee everywhere else.
 */
final class VariantSelectionMatcher
{
    private function __construct() {}

    /** @param list<VariantSelection> $selections */
    public static function matchesAll(ProductCard $unit, array $selections): bool
    {
        foreach ($selections as $selection) {
            if (!self::matches($unit, $selection)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(ProductCard $unit, VariantSelection $selection): bool
    {
        if ($selection->group !== null) {
            return ($unit->options[$selection->group] ?? null) === $selection->option;
        }

        return \in_array($selection->option, $unit->options, strict: true);
    }
}
