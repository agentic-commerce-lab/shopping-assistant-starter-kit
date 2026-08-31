<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;

/**
 * Picks the passage store this shop can actually run — the container's factory for `PassageStore`.
 *
 * **The store is chosen, not configured** (spec D1). No merchant can answer "which vector storage
 * engine", and the answer is not a preference anyway: it is a fact about the shop's database and its
 * vendor tree, which {@see ShopInfoAvailability} has already probed.
 *
 * **MariaDB is preferred, and that direction is the one worth guarding.** Where
 * {@see AiStorePassageStore} works it keeps answering, unchanged, because it has a real vector index
 * behind it. Falling back where the fast path exists would be a silent performance regression on
 * exactly the shops with the largest document sets — visible as "the assistant got slow", months
 * later, with nothing pointing here. {@see DalPortablePassageStore} is not a lesser version of it but
 * a different trade: no index, exact rather than approximate results, and it runs everywhere Shopware
 * does.
 *
 * **The two stores never share data.** They write to different tables and nothing copies between
 * them, so a shop that moves from one to the other sees nothing found until its documents are
 * indexed again. {@see ShopInfoAvailability::fastPathUnmet()} carries that sentence, because the
 * shop most likely to hit it is a MariaDB shop whose next `composer update` dropped the now merely
 * suggested bridge package.
 *
 * **`choose()` runs on every shop, including the ones with shop information switched off.**
 * `PassageStore` is a plain constructor argument of `DocumentIngestionFactory`,
 * `SearchShopInfoToolFactory` and `PassageLookup`, so the container builds it whenever it builds any
 * of those three — it has no way of knowing that `embeddingModel` is empty. **So this method must
 * stay free of side effects.** Anything recorded from here would fire on shops that never retrieve a
 * passage, which is why the `retrieve.shopinfo.store` trace event that names the active store
 * belongs in `SearchShopInfoToolFactory::create()`, after its `embeddingModel === ''` early return,
 * and not here.
 *
 * It cannot fail. The portable store needs nothing from the database beyond an ordinary table, so
 * there is always an answer to give — which is exactly how this differs from
 * {@see ShopInfoAvailability::isAvailable()}: that one asks whether the feature can run at all
 * (`symfony/ai-store` present, so a question can be embedded), never which store would serve it.
 *
 * A static factory rather than an injected strategy object because the answer is settled once per
 * container instance, from state that cannot change while the process runs. Symfony invokes a
 * factory at runtime, on the first `get()` of the service — as it must here, since the answer
 * depends on a live database probe and a `class_exists()`. The same shape as
 * {@see ShopInfoAvailability::detect()}.
 */
final class PassageStoreChooser
{
    public static function choose(
        ShopInfoAvailability $availability,
        AiStorePassageStore $mariaDb,
        DalPortablePassageStore $portable,
    ): PassageStore {
        return $availability->nativeVectorStoreUsable() ? $mariaDb : $portable;
    }
}
