<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Split out of {@see FactRenderer} once the case-insensitive id lookup this class
 * exists for pushed `FactRenderer`'s own cyclomatic-complexity total over this
 * project's threshold (mago sums it per class, across every method) — same
 * reasoning, and the same fix, as {@see \Swag\AssistantStarterKit\Core\Agent\MalformedToolArgumentRejection}
 * being split out of {@see \Swag\AssistantStarterKit\Core\Agent\BoundedToolbox}.
 *
 * Holds the id-keyed "authoritative set" of every card a turn has retrieved, keyed
 * both by the card's own id and, alongside it, by that id lowercased so a candidate
 * id can be resolved case-insensitively — Shopware entity ids are hex and this
 * project's fixture ids are lowercase ASCII, so in neither is case meaningful, and a
 * model that upper-cases an id it otherwise got right (e.g. "FX-021" for retrieved
 * "fx-021") must still resolve to it. Two ids differing only in case cannot both be
 * real entities, so a collision in the lowercase index is impossible in practice —
 * but if {@see self::register()} ever received such a pair, later wins, exactly the
 * same overwrite rule the id-keyed map itself already follows for an exact-id
 * collision.
 */
final class RetrievedProductIndex
{
    /** @var array<string, ProductCard> */
    private array $byId = [];

    /** @var array<string, string> lowercase id => canonical id */
    private array $byLowercaseId = [];

    /**
     * @param list<ProductCard> $cards
     */
    public function register(array $cards): void
    {
        foreach ($cards as $card) {
            // A later registration for the same id overwrites the earlier one:
            // a resolved variant supersedes its parent.
            $this->byId[$card->id] = $card;
            $this->byLowercaseId[strtolower($card->id)] = $card->id;
        }
    }

    /**
     * @return list<string> every id registered so far, in registration order
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * Resolves any casing of a registered id to its canonical (as-registered) id.
     * Returns null when `$id` matches nothing registered, in any casing.
     */
    public function canonicalId(string $id): ?string
    {
        return $this->byLowercaseId[strtolower($id)] ?? null;
    }

    public function card(string $canonicalId): ?ProductCard
    {
        return $this->byId[$canonicalId] ?? null;
    }
}
