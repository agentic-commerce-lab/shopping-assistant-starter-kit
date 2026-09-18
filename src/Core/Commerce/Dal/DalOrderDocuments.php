<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * An order's downloadable documents, as links the SHOP renders.
 *
 * Its own class because both {@see DalOrderMapper}'s mappings need it and together they took that
 * class over mago's complexity threshold — but also because it is the one place that turns a
 * document id into a URL, and that deserves a single owner rather than two call sites.
 *
 * **Which documents exist is never decided here.** `OrderRoute` has already filtered them to
 * `config.displayInCustomerAccount = true` and `sent = true`, on orders the B2B employee filter
 * already narrowed. Nothing in this class filters, and nothing in it may start to.
 */
final readonly class DalOrderDocuments
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    /** @return list<OrderDocumentRef> */
    public function of(OrderEntity $order): array
    {
        $refs = [];

        foreach ($order->getDocuments() ?? [] as $document) {
            $deepLinkCode = $document->getDeepLinkCode();

            if ($deepLinkCode === null) {
                continue;
            }

            $refs[] = new OrderDocumentRef(
                title: (string) ($document->getDocumentType()?->getTranslation('name') ?? 'Document'),
                // Built from the route, server-side, and never handed to the model — the same rule as
                // the checkout link and the contact URL (D3). That route is login-required and
                // re-authenticates, so a copied link is a link the browser still has to earn.
                url: $this->router->generate(
                    'frontend.account.order.single.document',
                    ['documentId' => $document->getId(), 'deepLinkCode' => $deepLinkCode],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
                extension: 'pdf',
            );
        }

        return $refs;
    }
}
