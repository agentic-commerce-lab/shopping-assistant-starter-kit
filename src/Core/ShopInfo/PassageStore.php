<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Where passages live, as `Core` is allowed to see it.
 *
 * **Its own interface rather than `symfony/ai`'s `StoreInterface`**, for the same reason `Core` may
 * not name a Shopware type: an implementation may use a Symfony bridge, a different vector database,
 * or an array, and nothing above this line should be able to tell. It is also what lets
 * {@see SearchShopInfoTool} be unit-tested with a short fake instead of a configured store, an API
 * key and a database.
 *
 * **This interface speaks similarity, not distance.** `$minScore` is a similarity in `0.0..1.0` where
 * higher means more similar, and {@see ShopInfoPassage::$score} is the same. That is a deliberate
 * choice and the one thing here most likely to be "simplified" into a sign error: the MariaDB vector
 * store underneath returns a cosine *distance*, where **lower** is more similar and identical vectors
 * score `0`. Every implementation owns that conversion. The reason `Core` speaks similarity is that
 * spec R4's calibration and R5's trace are both written in it — "we would have had the answer at
 * 0.62" only reads correctly one way round — and inverting the comparison in the tool as well would
 * put the same sign in two places.
 *
 * **Every method takes or filters by a sales-channel id.** The legal pages behind these passages are
 * configured per channel, so two channels genuinely have different terms; a query without that filter
 * serves one shop's revocation notice in another (spec R12). It is a parameter rather than
 * constructor state so a single store instance can serve a multi-channel shop.
 *
 * @api An extension point. Replace the service to keep passages somewhere other than the two shipped
 *      stores — the MariaDB vector table, or the portable JSON table it falls back to;
 *      {@see \Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser} is the shipped factory that
 *      picks between them. `docs/extending.md` documents this, so adding a method here breaks a
 *      store someone else wrote.
 */
interface PassageStore
{
    /**
     * @param list<ShopInfoPassage> $passages
     * @param list<list<float>>     $vectors  one per passage, same order, same length
     *
     * @throws \RuntimeException when the counts disagree, or a vector's width differs from the
     *                           store's and the store already holds passages at the other width
     */
    public function add(array $passages, array $vectors, string $salesChannelId): void;

    /**
     * Passages at or above `$minScore` similarity, most similar first, with their scores set.
     *
     * @param list<float> $vector
     *
     * @return list<ShopInfoPassage>
     */
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array;

    /** Every passage of one document, so a new version can replace the old one (spec R8). */
    public function deleteDocument(string $documentId): void;

    /**
     * The vector width this store holds, or null when it holds nothing.
     *
     * A store holds exactly one width — a `VECTOR(n)` column in the MariaDB store, a recorded
     * `dimension` in the portable one — while the model is a merchant setting, so switching models
     * invalidates everything already stored. Callers compare this against the width the configured
     * model produces and refuse rather than return nonsense — see the plan's *Two gaps* section.
     */
    public function dimension(): ?int;
}
