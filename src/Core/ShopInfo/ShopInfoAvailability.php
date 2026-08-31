<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Two different questions about shop-information retrieval, deliberately kept apart: **can this shop
 * run it at all**, and **is the fast store the one answering**.
 *
 * **The failure this exists for, measured on a live shop on 2026-08-31.** The staging shop had an
 * embedding model configured, so `SearchShopInfoToolFactory` built the tool on every turn, and every
 * shopper message came back a 500: *Attempted to load class "Vectorizer" from namespace
 * "Symfony\AI\Store\Document"*. The shop's `vendor/` had never installed `symfony/ai-store`. A
 * feature nobody was using took the whole assistant down.
 *
 * ## "Can it run" — {@see self::unmetRequirement()}
 *
 * One requirement, and it is the one that shop was missing: **`symfony/ai-store`**. It carries
 * {@see \Symfony\AI\Store\Document\Vectorizer}, which {@see PlatformEmbedder} needs to turn text into
 * a vector at all, so without it there is nothing for any store to hold or search. Shopware autoloads
 * a plugin's dependencies out of the SHOP's vendor tree, so a plugin deployed as a symlink or by
 * rsync can be running with the package simply absent.
 *
 * **A MariaDB used to be a second hard requirement here, and is not one any more.** While
 * {@see \Swag\AssistantStarterKit\ShopInfo\AiStorePassageStore} was the only store this plugin
 * shipped, a shop whose database could not answer `VEC_DISTANCE_COSINE` could not run the feature —
 * and no MySQL version can, at any version, because MySQL Community has no distance function
 * (`DISTANCE()` is HeatWave-only). {@see \Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore}
 * keeps vectors as JSON in an ordinary table and ranks them in PHP, so it runs everywhere Shopware
 * does. The database question therefore stopped meaning *broken* and started meaning *slower*, which
 * is a different sentence to a different reader — so it moved to its own pair of methods below.
 *
 * ## "Is the fast path in use" — {@see self::nativeVectorStoreUsable()} and {@see self::fastPathUnmet()}
 *
 * MariaDB is **preferred, not required**: where the database and the `symfony/ai-maria-db-store`
 * package both allow it, the indexed store still answers, unchanged. `nativeVectorStoreUsable()` is
 * the one place that question is now asked — {@see \Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser}
 * reads it — and `fastPathUnmet()` is the sentence that goes with a *no*, because spec D6 requires
 * the fallback to announce itself. Replacing an honest error with an invisible degradation would be
 * the opposite of what the rest of this plugin does: a MariaDB shop whose operator overlooked the
 * suggested package would otherwise experience this only as "it got slow".
 *
 * ## What an unmet requirement does about it: nothing dramatic
 *
 * It makes the feature *off* — the exact state an empty `embeddingModel` already means everywhere in
 * this plugin (spec R13), with no tool in the toolbox and nothing in the model's schema.
 * {@see \Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig} applies it at the one
 * place every caller passes through, so a merchant who typed a model name into a shop that cannot
 * use it gets a switched-off feature instead of a broken assistant. A missing fast path is not an
 * unmet requirement at all: it changes which store answers and nothing else.
 */
final readonly class ShopInfoAvailability
{
    /**
     * The class whose absence proves `symfony/ai-store` is not installed — the one this plugin
     * actually constructs.
     *
     * A list of one, kept as a list because the shape is "every class the feature cannot run
     * without". `Symfony\AI\Store\Bridge\MariaDb\Store` left it when the portable store landed: its
     * absence now costs speed, not the feature, and that is {@see self::NATIVE_STORE_CLASS}'s
     * question rather than this one's.
     *
     * @var list<class-string>
     */
    private const REQUIRED_CLASSES = [
        'Symfony\AI\Store\Document\Vectorizer',
    ];

    /**
     * The MariaDB bridge, which `symfony/ai-maria-db-store` carries and which `composer.json` only
     * *suggests*. Named rather than inlined because two methods below have to agree on it.
     */
    private const NATIVE_STORE_CLASS = 'Symfony\AI\Store\Bridge\MariaDb\Store';

    /**
     * Both installed-ness flags are constructor arguments rather than `class_exists()` calls inside
     * the methods below, so each shortfall can be tested on its own. The container passes
     * {@see self::packagesInstalled()} and {@see self::nativeStoreInstalled()}.
     *
     * `$nativeStoreInstalled` is defaulted to `true` so that every construction written before the
     * portable store existed — the config tests, the eval harness — keeps meaning what it meant: a
     * shop whose only variable was the database.
     */
    public function __construct(
        private VectorSupport $vectors,
        private bool $packagesInstalled,
        private bool $nativeStoreInstalled = true,
    ) {}

    /**
     * The container's entry point: a factory rather than a plain service, because whether a package
     * is installed is a `class_exists()` question and a DI parameter cannot ask one.
     */
    public static function detect(VectorSupport $vectors): self
    {
        return new self($vectors, self::packagesInstalled(), self::nativeStoreInstalled());
    }

    public static function packagesInstalled(): bool
    {
        foreach (self::REQUIRED_CLASSES as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    public static function nativeStoreInstalled(): bool
    {
        return class_exists(self::NATIVE_STORE_CLASS);
    }

    public function isAvailable(): bool
    {
        return $this->unmetRequirement() === null;
    }

    /**
     * Null when the shop can run the feature; otherwise one sentence naming what to do about it.
     *
     * **This asks about the feature, never about which store serves it.** The database is not
     * mentioned here even when it cannot serve the indexed store, because a merchant told to change
     * database engine when a `composer require` would have done is a merchant who stops reading —
     * and on this shop the engine is not the problem at all. The engine's own sentence lives in
     * {@see self::fastPathUnmet()}.
     */
    public function unmetRequirement(): ?string
    {
        if (!$this->packagesInstalled) {
            return (
                'Shop information needs the "symfony/ai-store" package, which this shop\'s vendor '
                . 'directory does not have. Install it into the shop (not only into the plugin), '
                . 'then reload.'
            );
        }

        return null;
    }

    /**
     * Whether the indexed MariaDB store can answer on this shop — the chooser's only input (spec D1).
     *
     * Both halves are needed and neither implies the other: a MariaDB 11.8 shop without the bridge
     * package cannot use it, and a shop carrying the package on MySQL cannot either.
     */
    public function nativeVectorStoreUsable(): bool
    {
        return $this->vectors->isAvailable() && $this->nativeStoreInstalled;
    }

    /**
     * Null while the indexed store is in use; otherwise a short paragraph a human can act on.
     *
     * This is what the `retrieve.shopinfo.store` trace event carries and what the merchant reads, so
     * it says what the fallback *does* rather than only that it happened: exact rather than
     * approximate results, unremarkable for a shop's legal pages and its own uploads, and a linear
     * scan once a corpus reaches thousands of passages.
     *
     * **The database is reported before the package, the opposite way round from
     * {@see self::unmetRequirement()}.** There the package is the cheap and sufficient fix. Here it
     * is neither: installing the bridge on MySQL changes nothing, so offering the `composer require`
     * would send an operator after a command that cannot work.
     *
     * `null` is returned from exactly one place — the guard below — so this method cannot disagree
     * with {@see self::nativeVectorStoreUsable()} about whether the fast path is in use. Spelling
     * the conditions again here and negating them would hold that invariant only by coincidence: a
     * third condition added to the fast path would make this method fall silent on a shop that had
     * just lost it, and the trace event and the merchant's screen would both say nothing was wrong.
     */
    public function fastPathUnmet(): ?string
    {
        if ($this->nativeVectorStoreUsable()) {
            return null;
        }

        if (!$this->vectors->isAvailable()) {
            return \sprintf(
                'Shop information is answering from its portable passage store: the indexed MariaDB '
                . 'store needs MariaDB 11.7 or newer for VECTOR columns and VEC_DISTANCE_COSINE, and '
                . 'this shop runs %s, so the assistant compares vectors in PHP instead — exact rather '
                . 'than approximate, unremarkable for a few hundred passages and slower on thousands. '
                . 'No MySQL version can serve the indexed store, so only changing engine restores it.',
                $this->vectors->describe(),
            );
        }

        // The remaining case, not a third condition: the fast path is unmet and the database is not
        // why, so the package is. Left as a fallthrough deliberately — if `nativeVectorStoreUsable()`
        // ever grows a third condition, this returns the wrong sentence rather than no sentence, and
        // a wrong sentence is something a reader reports.
        //
        // **It names the re-indexing, not only the package.** This is the state a MariaDB shop lands
        // in the first time `composer update` drops a package that is now only *suggested*: retrieval
        // keeps working, but against an empty table, because the two stores share no data. "Slower"
        // would be a comforting description of a shop that has quietly stopped finding anything.
        return (
            'Shop information is answering from its portable passage store: this shop\'s database '
            . 'could run the indexed MariaDB store, but the "symfony/ai-maria-db-store" package is '
            . 'not in its vendor directory, so the assistant compares vectors in PHP instead — exact '
            . 'rather than approximate, unremarkable for a few hundred passages and slower on '
            . 'thousands. Documents indexed while that package was installed are still in the other '
            . 'store\'s table and this one cannot read them, so either put the package back with '
            . '"composer require symfony/ai-maria-db-store" in the shop, or index the documents '
            . 'again.'
        );
    }
}
