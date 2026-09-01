<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;

/**
 * Which passage store this shop is using, why, and whether the other one still holds documents.
 *
 * **The half of spec D6 the trace event does not cover.** `retrieve.shopinfo.store` records the
 * choice on every turn, which makes it findable to whoever debugs one. Nothing made it findable to
 * the merchant whose shop simply got slower — or, worse, stopped finding anything.
 *
 * That second case is why `otherStoreHasPassages` exists and is the only field here a merchant must
 * act on. `symfony/ai-maria-db-store` is suggested rather than required, so the first `composer
 * update` on a MariaDB shop can remove it. {@see PassageStoreChooser} then hands out
 * {@see DalPortablePassageStore}, which reads a table the MariaDB store never wrote a row to. The
 * documents are not gone; they are in the other table, invisible. From the shop floor that looks
 * like the index was lost, and a merchant with no way to see which store is answering has no way to
 * tell those apart.
 *
 * Read-only and derived on demand. Nothing here is stored, because every input can change without
 * this plugin being involved: a database upgrade, a `composer` run, a restore from a dump.
 */
final readonly class PassageStoreStatus
{
    public function __construct(
        private ShopInfoAvailability $availability,
        private AiStorePassageStore $mariaDb,
        private DalPortablePassageStore $portable,
    ) {}

    /**
     * @return array{store: 'mariadb'|'portable', reason: ?string, otherStoreHasPassages: bool}
     */
    public function describe(): array
    {
        $native = $this->availability->nativeVectorStoreUsable();

        return [
            'store' => $native ? 'mariadb' : 'portable',
            // Null whenever the fast path is in use: there is nothing to explain, and a screen that
            // prints a reason beside a healthy state teaches a merchant to stop reading reasons.
            'reason' => $this->availability->fastPathUnmet(),
            'otherStoreHasPassages' => $this->holdsPassages($native ? $this->portable : $this->mariaDb),
        ];
    }

    /**
     * Whether a store holds anything, asked through the width it reports.
     *
     * `dimension()` is null on an empty store and a number on one holding vectors, so it answers this
     * without a second method on {@see PassageStore} — which `docs/extending.md` publishes as an
     * extension point, where a new method would break every store somebody else wrote.
     *
     * **Shop-wide rather than per channel**, because that is the honest limit of what `dimension()`
     * can say. The notice it feeds is worded to match: it tells a merchant documents are sitting in
     * the other store, not how many or in which channel.
     */
    private function holdsPassages(PassageStore $store): bool
    {
        try {
            return $store->dimension() !== null;
        } catch (\Throwable) {
            // Spec requirement 5, applied to a screen rather than a shopper. A store that cannot be
            // asked — no table, no package, a database mid-migration — is one this shop is not using
            // anyway. Reporting "nothing there" costs a merchant one notice they did not need;
            // letting it escape costs them the whole settings page.
            return false;
        }
    }
}
