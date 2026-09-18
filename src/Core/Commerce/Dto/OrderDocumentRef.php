<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One downloadable document of an order, as the card shows it.
 *
 * Shaped like {@see ProductDocument} on purpose: {@see \Swag\AssistantStarterKit\Controller\CardDocuments}'
 * collapse rule and `card.js`'s document rows are then reused rather than re-derived, and the two
 * kinds of document row on screen stay the same row.
 *
 * `$url` is built server-side from the `frontend.account.order.single.document` route and **never
 * reaches the model** — the same rule as the checkout link and the contact URL (D3). That route is
 * login-required and re-authenticates the caller, so a copied link is not a leak: it is a link to a
 * page the browser has to earn.
 */
final readonly class OrderDocumentRef
{
    public function __construct(
        public string $title,
        public string $url,
        public string $extension,
    ) {}
}
