<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoSync;
use Swag\AssistantStarterKit\ShopInfo\ShopPageIndexer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two actions that operate on a whole sales channel rather than one document.
 *
 * Separate from {@see ShopInfoDocumentController} because the unit of work is different: that class
 * acts on a document a merchant picked, this one on everything a channel has. It also keeps both
 * inside Mago's per-class bounds, which is how this codebase has split every controller so far.
 *
 * Not an `AbstractController`, following the others: no container, no twig, no `setContainer()`.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class ShopInfoSyncController
{
    public function __construct(
        private readonly ShopPageIndexer $pages,
        private readonly ShopInfoSync $sync,
        private readonly SystemConfigAssistantConfig $config,
    ) {}

    /**
     * Index the shop's own legal pages for one channel (spec R1).
     *
     * The documents a shopper actually asks about, and the ones a merchant should never have had to
     * upload: the shop already has them, configured under `core.basicInformation`.
     */
    #[Route(
        path: '/api/_action/swag-assistant/shop-info/index-pages',
        name: 'api.action.swag_assistant.shop_info.index_pages',
        defaults: ['_acl' => ['swag_assistant_document:create']],
        methods: ['POST'],
    )]
    public function indexPages(Request $request): JsonResponse
    {
        $salesChannelId = (string) $request->request->get('salesChannelId', '');

        if ($salesChannelId === '') {
            return self::refusal('A sales channel is required.');
        }

        return $this->bulk(fn(string $model): array => $this->pages->indexPages(
            $salesChannelId,
            $model,
        ), $salesChannelId);
    }

    /**
     * Index every document of the channel again, each from its own source.
     *
     * What a merchant needs after changing the embedding model, which invalidates every stored vector.
     */
    #[Route(
        path: '/api/_action/swag-assistant/shop-info/reindex-all',
        name: 'api.action.swag_assistant.shop_info.reindex_all',
        defaults: ['_acl' => ['swag_assistant_document:update']],
        methods: ['POST'],
    )]
    public function reindexAll(Request $request): JsonResponse
    {
        $salesChannelId = (string) $request->request->get('salesChannelId', '');

        if ($salesChannelId === '') {
            return self::refusal('A sales channel is required.');
        }

        return $this->bulk(fn(string $model): array => $this->sync->reindexAll(
            $salesChannelId,
            $model,
        ), $salesChannelId);
    }

    /**
     * Runs a bulk action and reports the counts, refusing early when the feature is off.
     *
     * The off case is checked here rather than left to fail per item: with no embedding model every
     * page would fail for the same reason, and a merchant would read five identical failures instead
     * of one sentence telling them to configure a model.
     *
     * @param callable(string): array{indexed: int, failed: list<array{name: string, reason: string}>, skipped: list<string>} $run
     */
    private function bulk(callable $run, string $salesChannelId): JsonResponse
    {
        $model = $this->config->forSalesChannel($salesChannelId)->embeddingModel;

        if ($model === '') {
            return self::refusal(
                'No embedding model is configured for this sales channel, so shop information is '
                . 'switched off. Set one in the plugin settings first.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse($run($model));
    }

    private static function refusal(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['errors' => [['detail' => $message]]], $status);
    }
}
