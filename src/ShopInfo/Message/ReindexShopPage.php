<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * One of the shop's own pages needs indexing again, for one sales channel.
 *
 * **`AsyncMessageInterface` is what actually makes this asynchronous**, and it is not decoration.
 * Shopware routes only messages carrying that marker to a transport; everything else is handled inline
 * on the dispatching request. Measured on 2026-08-26 without it: `messenger_messages` stayed empty and
 * the page's embedding call ran inside the merchant's CMS save. The whole point is that it does not —
 * embedding is a network call to a paid provider, and a provider timeout must not surface as a failure
 * to save content that was in fact saved. The queue is also what makes a burst of edits cheap.
 *
 * Per channel and per page, not "re-index everything": the same page can be configured for several
 * channels with different terms, and re-reading all five pages because one changed would spend five
 * times the embedding calls the change actually earned.
 */
final readonly class ReindexShopPage implements AsyncMessageInterface
{
    public function __construct(
        public string $salesChannelId,
        public string $cmsPageId,
    ) {}
}
