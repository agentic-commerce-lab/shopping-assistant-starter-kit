<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * What a single invocation of the bike seeder is allowed to do.
 *
 * **Three named modes rather than two booleans**, because two booleans have four states and one of
 * them (`--dry-run --update`) has no obvious meaning at the call site. Naming the modes puts that
 * decision in one readable place and lets it be tested without a database — see
 * {@see \Swag\AssistantStarterKit\Tests\Command\Seed\Bike\BikeSeedModeTest} for why that mattered.
 *
 * ## Why `Update` exists, and why it is not the `--force` the guard forbids
 *
 * `SeedGuard` carries a written rule: *"Do not add `--force`: the recovery for 'I seeded twice' is a
 * database restore either way."* That rule is about **doubling a catalogue**, and it is right about
 * `--force`, which would re-run a first seed end to end.
 *
 * `Update` cannot double anything, and the reason is structural rather than careful:
 * {@see \Swag\AssistantStarterKit\Command\Seed\SeedId::forPath()} derives every id — product,
 * category, property group, property option, marker — from a stable string, and
 * {@see \Swag\AssistantStarterKit\Command\Seed\SeedWriter} upserts. A second run over an unchanged
 * catalogue writes the same ids and is therefore an update by construction. What `Update` adds is
 * permission to do that on a shop that already carries the marker.
 *
 * **The one thing that would break it: renaming or renumbering a product.** A changed
 * `productNumber` is a different derived id, so the old row stays and the new one appears beside it —
 * the doubling the guard warns about, arriving through the catalogue rather than through the flag. So
 * `Update` is for adding to or correcting existing entries, and a renumbering is still a restore.
 * There is deliberately no flag that makes that case safe.
 *
 * `Update` also never marks: see {@see self::marks()}.
 */
enum BikeSeedMode
{
    /** The first seed of a shop: refuses a marked shop, writes, and marks when it finishes. */
    case Seed;

    /** Re-enters an already-seeded shop to upsert the catalogue, without re-marking it. */
    case Update;

    /** Resolves the plan against the live shop and reports it, writing nothing. */
    case DryRun;

    /**
     * `--dry-run` wins over `--update`, which is the safer reading of a request for both: it reports
     * what an update would do instead of doing it.
     */
    public static function of(bool $dryRun, bool $update): self
    {
        if ($dryRun) {
            return self::DryRun;
        }

        return $update ? self::Update : self::Seed;
    }

    /**
     * Whether the marker on an already-seeded shop should stop this run.
     *
     * Only a first seed is stopped. A dry run is not, because it writes nothing and reporting the
     * plan for a seeded shop is a legitimate thing to want.
     */
    public function refusesASeededShop(): bool
    {
        return $this === self::Seed;
    }

    /**
     * Whether the *absence* of the marker should stop this run.
     *
     * The mirror of {@see self::refusesASeededShop()}, and only `Update` is subject to it: an update
     * is defined as re-entering a catalogue that is already there. Run against a shop that was never
     * seeded it would write every product without ever running completion, leaving them unindexed —
     * present in the administration and invisible to the assistant. Refusing is the only outcome that
     * does not require the operator to notice.
     */
    public function requiresASeededShop(): bool
    {
        return $this === self::Update;
    }

    /**
     * Why this run may not proceed against a shop in this state, or null when it may.
     *
     * **A message rather than a bool**, because a refusal that does not name the way forward sends the
     * operator to the source. The two directions are stated together here for the same reason the two
     * predicates above are: the rule is one rule, and reading half of it is how somebody concludes
     * that `--update` is a `--force`.
     */
    public function refusalFor(bool $seeded): ?string
    {
        if ($this->refusesASeededShop() && $seeded) {
            return (
                'This shop already carries the bike seed marker — refusing to seed twice. To '
                . 'upsert the catalogue into it instead — correcting or extending the products '
                . 'already there — re-run with --update. Recovery from an actual double seed is a '
                . 'database restore, not a re-run.'
            );
        }

        if ($this->requiresASeededShop() && !$seeded) {
            return (
                'This shop carries no bike seed marker, so there is nothing to update. Run '
                . 'without --update to seed it for the first time.'
            );
        }

        return null;
    }

    /**
     * Whether this run writes the payload parts whose rows carry ids the **DAL mints** rather than ids
     * this seeder derives: `visibilities`, `children` and `configuratorSettings`.
     *
     * Only a first seed does, and the reason is measured rather than cautious. Both failures below came
     * out of the staging shop, one per attempt:
     *
     * - `Configuration option already exists` — {@see BikeVariantFamily} emits a configurator setting
     *   as `['optionId' => …]` with no id, so a second write asks for a row
     *   `product_configurator_setting` already holds under its unique (product, option) key.
     * - `Duplicate entry … for key 'product_visibility.uniq.product_id__sales_channel_id'` — the same
     *   shape. `product_visibility` is a real entity with its own id and a unique pair.
     *
     * **Deriving the ids from here on would not fix either**, which is the point worth keeping: the
     * rows already in a seeded shop carry the random ids from *its* first seed, so nothing this seeder
     * computes now can match them.
     *
     * `categories` and `properties` are deliberately not in this set. They are plain many-to-many
     * mapping tables keyed by the pair itself — there is no minted id to collide with — and they are
     * exactly what an update exists to change.
     *
     * **A third limit, of a different kind: `--update` cannot *remove* a property.** `properties` is a
     * many-to-many association and `upsert` merges into it — a shorter list adds nothing and deletes
     * nothing. So dropping a value from {@see BikeCatalogue::descriptiveGroups()} (as `Polystyrene` and
     * `Rubber` were dropped, for discriminating nothing) fixes new shops and leaves an already-seeded
     * one carrying the old assignment. Clearing those needs a delete against `product_property`, which
     * this seeder deliberately does not do: it has no way to tell a value it wrote from one the
     * merchant added.
     *
     * The cost, stated plainly: **`--update` cannot change a product's variant axes or its sales
     * channel visibility.** Adding a colour to an existing family, or exposing the catalogue in a
     * second storefront, needs a restore and a fresh seed — the same as renumbering a product. What it
     * can change is the rest: properties, name, description, price, categories.
     */
    public function writesFirstSeedStructures(): bool
    {
        return $this === self::Seed;
    }

    public function writes(): bool
    {
        return $this !== self::DryRun;
    }

    /**
     * Whether this run has to reindex what it wrote.
     *
     * **Not the same question as {@see self::marks()}, though it was answered by it once.** Both live
     * inside {@see \Swag\AssistantStarterKit\Command\Seed\SeedCompletion::complete()}, so gating
     * that one call on the marker skipped the reindex too — and the first working `--update` against
     * staging therefore needed `dal:refresh:index` run by hand afterwards. Shopware resolves a
     * variant's *inherited* properties through the product index, so an update that changed properties
     * and left the index alone is one the storefront filters cannot see.
     */
    public function indexes(): bool
    {
        return $this->writes();
    }

    /**
     * Whether this run ends by completing the seed — indexing, the refresh event, and the marker.
     *
     * Only a first seed does. An update runs against a shop whose catalogue is already complete and
     * indexed, and letting it re-mark would let a partial later run produce the "this finished"
     * signal that only a full first seed has earned.
     */
    public function marks(): bool
    {
        return $this === self::Seed;
    }
}
