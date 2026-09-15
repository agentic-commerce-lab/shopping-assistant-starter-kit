<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\UnitSpacing;

/**
 * The names a turn's retrieved products answer to, and which id each name stands for.
 *
 * Split out of {@see ProseProductNames} when resolving an ambiguous name pushed that class over this
 * project's per-class cyclomatic-complexity budget (mago sums it across every method) — the same
 * seam, and the same reason, as {@see RetrievedProductIndex} being split out of {@see FactRenderer}.
 * {@see ProseProductNames} keeps the one job of finding names in prose; deciding what a name points
 * at is this class's.
 *
 * **A name is not an identity.** Shopware names a variant after its parent, so every variant of a
 * family answers to the same name and a name can stand for several ids. `$preferredIds` is how the
 * caller settles it — see {@see ProseProductNames} for the wrong-card defect that made it necessary.
 */
final readonly class ProductNameIndex
{
    /** @var array<string, string> */
    private array $namesById;

    /**
     * @param array<string, string> $namesById   product id => the product's name
     * @param list<string>          $excludedIds ids this reply has ruled out — dropped here rather
     *                                           than skipped at lookup, so an excluded id is simply
     *                                           not in the index. See {@see ContradictedVariants}.
     */
    public function __construct(array $namesById, array $excludedIds = [])
    {
        // Normalised on the way in, so the two spellings of one measurement are ONE name here:
        // "Alloy Water Bottle 750 ml" and "Alloy Water Bottle 750ml" index as the same string and
        // therefore answer to the same mention in a reply. Doing it per lookup instead would give
        // them separate entries, and the longer one would mask the mention away from the shorter —
        // dropping the very card this exists to render. See {@see UnitSpacing} for why the shop
        // already treats them as one.
        $kept = array_diff_key($namesById, array_flip($excludedIds));

        $this->namesById = array_map(UnitSpacing::join(...), $kept);
    }

    /**
     * Every name worth searching for, longest first, each one once however many ids answer to it.
     *
     * Longest first is what lets {@see ProseProductNames} mask a matched name out of its working copy
     * and have a short name match only where it stands on its own.
     *
     * Names are carried as values rather than as array keys throughout: PHP casts a numeric string key
     * to an int, and a shop that names a product `2024` would then hand `strlen()` an int under
     * `strict_types`.
     *
     * Blank names are dropped: every string contains the empty string, so one would match every reply
     * and render a card for a product the shop failed to name.
     *
     * @return list<string>
     */
    public function namesLongestFirst(): array
    {
        $names = [];

        foreach ($this->namesById as $name) {
            if (trim($name) === '' || \in_array($name, $names, true)) {
                continue;
            }

            $names[] = $name;
        }

        usort($names, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $names;
    }

    /**
     * The one id this name stands for, or null when nothing registered carries it.
     *
     * A preferred id wins over registration order; among several preferred ids sharing the name, the
     * one the caller listed first, since that is the order the tool returned them in. Ids the
     * constructor excluded are not here to be found, so a name whose every candidate the reply ruled
     * out resolves to nothing and renders no card — the honest outcome when all of them contradict.
     *
     * @param list<string> $preferredIds
     */
    /**
     * The names it holds, keyed by id, with exclusions already applied.
     *
     * Exposed for {@see NamesakeCards}, which needs the same filtered map to decide how many cards a
     * name is worth — and must not re-derive it, or an excluded variant would come back as a card.
     *
     * @return array<string, string>
     */
    public function namesById(): array
    {
        return $this->namesById;
    }

    /**
     * The single id this name points at, or null when none answers to it.
     *
     * A preferred id wins over registration order — see the class docblock and
     * {@see NamesakeCards}, which applies the same tie-break once per family.
     *
     * @param list<string> $preferredIds
     */
    public function idFor(string $name, array $preferredIds): ?string
    {
        foreach ($preferredIds as $preferred) {
            if (($this->namesById[$preferred] ?? null) === $name) {
                return $preferred;
            }
        }

        foreach ($this->namesById as $id => $candidate) {
            if ($candidate === $name) {
                return $id;
            }
        }

        return null;
    }
}
