<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * A gateway that can stop counting once the answer is "very many".
 *
 * **A second interface rather than a parameter on {@see MatchCountReader}**, for the reason that one
 * gives for existing at all: it is marked `@api`, and adding a parameter to an interface method is a
 * fatal error in every implementation a merchant has already written. Implementing this is opt-in,
 * and a gateway that does not gets the uncapped count exactly as before.
 *
 * ## Why counting without a cap does not scale
 *
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\ExactMatchCount} runs one count per search
 * candidate, uncached, on every search. Measured on a 118,232-product shop, 2026-09-15:
 *
 * ```
 * "erstserie"   (1 match)          11 ms
 * "kettenführung" (few matches)     0,4 ms
 * "kette"       (~5.000 matches) 1.640 ms
 * ```
 *
 * The cost follows the size of the MATCH SET, not the catalogue — which is the happy shape: counting
 * is cheap exactly when the number is small, and that is exactly when the number is worth having.
 * *"There are 3 more"* is useful. *"There are 4.812 more"* says the same thing as *"very many"* to
 * anyone who is going to read it.
 *
 * Capping at 100 turned that 1.640 ms into 100–500 ms and, more importantly, made the cost depend on
 * the cap instead of on the shop. At 1.8M products the uncapped version was the single largest
 * remaining catalogue-proportional term in a turn.
 *
 * ## What an implementation owes the caller
 *
 * Everything {@see MatchCountReader} demands — the same set `search()` would retrieve, scope
 * included — plus one thing: the returned number must be **exact when it is below `$cap`**, and may
 * be `$cap` itself when the true answer is `$cap` or more. A caller cannot tell "exactly 100" from
 * "at least 100" and must not try; it reports "very many" for both, which is true either way.
 */
interface CappedMatchCountReader extends MatchCountReader
{
    /**
     * How many products `$query` matches under `$scope`, counting no further than `$cap`.
     *
     * @param positive-int $cap the point beyond which the exact number stops being worth its cost
     *
     * @return int exact below `$cap`; `$cap` when the true count reaches it
     */
    public function countMatchesUpTo(ProductQuery $query, CatalogScope $scope, int $cap): int;
}
