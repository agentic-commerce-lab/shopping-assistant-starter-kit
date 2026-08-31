<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Whether this shop can run shop-information retrieval at all — and if not, which requirement it is
 * missing.
 *
 * **The failure this exists for, measured on a live shop on 2026-08-31.** The staging shop had an
 * embedding model configured, so `SearchShopInfoToolFactory` built the tool on every turn, and every
 * shopper message came back a 500: *Attempted to load class "Vectorizer" from namespace
 * "Symfony\AI\Store\Document"*. The shop's `vendor/` had never installed `symfony/ai-store`. A
 * feature nobody was using took the whole assistant down.
 *
 * Two requirements, independent, and that shop met neither:
 *
 * 1. **The packages.** `symfony/ai-store` and `symfony/ai-maria-db-store` are `composer.json`
 *    requires of this plugin, but Shopware autoloads a plugin's dependencies out of the SHOP's vendor
 *    tree — so a plugin deployed as a symlink or by rsync can be running with them simply absent.
 * 2. **A MariaDB.** {@see \Symfony\AI\Store\Bridge\MariaDb\Store} emits `VEC_DISTANCE_COSINE`,
 *    `VEC_FromText` and `VECTOR INDEX`, which is MariaDB's spelling. MySQL gained a vector type only
 *    in 9.0 and named its functions differently (`STRING_TO_VECTOR`, `DISTANCE`), so **no** MySQL
 *    version runs this store. Worth saying out loud because it is the opposite of the intuitive fix:
 *    upgrading MySQL does not help, changing engine does.
 *
 * **What it does about it: nothing dramatic.** An unmet requirement makes the feature *off* — the
 * exact state an empty `embeddingModel` already means everywhere in this plugin (spec R13), with no
 * tool in the toolbox and nothing in the model's schema. {@see \Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig}
 * applies it at the one place every caller passes through, so a merchant who typed a model name into
 * a shop that cannot use it gets a switched-off feature instead of a broken assistant.
 */
final readonly class ShopInfoAvailability
{
    /**
     * The two classes whose absence proves the packages are not installed. One from each package,
     * chosen because they are the two this plugin actually constructs.
     *
     * @var list<class-string>
     */
    private const REQUIRED_CLASSES = [
        'Symfony\AI\Store\Document\Vectorizer',
        'Symfony\AI\Store\Bridge\MariaDb\Store',
    ];

    /**
     * `$packagesInstalled` is a constructor argument rather than a `class_exists()` call inside the
     * methods below, so the two shortfalls can be tested apart. The container passes
     * {@see self::packagesInstalled()}.
     */
    public function __construct(
        private VectorSupport $vectors,
        private bool $packagesInstalled,
    ) {}

    /**
     * The container's entry point: a factory rather than a plain service, because whether the
     * packages are installed is a `class_exists()` question and a DI parameter cannot ask one.
     */
    public static function detect(VectorSupport $vectors): self
    {
        return new self($vectors, self::packagesInstalled());
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

    public function isAvailable(): bool
    {
        return $this->unmetRequirement() === null;
    }

    /**
     * Null when the shop can run it; otherwise one sentence naming what to do about it.
     *
     * **Packages first when both are missing.** They are by far the cheaper fix, and a merchant told
     * to change database engine when a `composer require` would have done is a merchant who stops
     * reading the message.
     */
    public function unmetRequirement(): ?string
    {
        if (!$this->packagesInstalled) {
            return (
                'Shop information needs the "symfony/ai-store" and "symfony/ai-maria-db-store" '
                . 'packages, which this shop\'s vendor directory does not have. Install them into the '
                . 'shop (not only into the plugin), then reload.'
            );
        }

        if (!$this->vectors->isAvailable()) {
            return \sprintf(
                'Shop information needs MariaDB 11.7 or newer, whose VECTOR column and VEC_DISTANCE '
                . 'functions the passage store is written against. This shop runs %s. No MySQL '
                . 'version can serve it: MySQL added vectors in 9.0 under different function names, '
                . 'so upgrading MySQL will not help — the engine has to change.',
                $this->vectors->describe(),
            );
        }

        return null;
    }
}
