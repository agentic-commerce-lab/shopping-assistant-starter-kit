<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\ShopInfo\DocumentIngestionFactory;
use Swag\AssistantStarterKit\ShopInfo\PassageLookup;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoSync;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Upload, re-index and delete shop information documents from the Administration.
 *
 * The list itself is not here: `swag_assistant_document` is a DAL entity, so the Administration reads
 * it through the generated `/api/swag-assistant-document` repository like any other entity. Writing a
 * second read endpoint would mean a second set of filters and sorting to keep in step with it.
 *
 * **Deleting is here, though, and that is the point of the class.** The DAL would happily delete the
 * row, and the passages in the vector store carry the document id as JSON metadata with no foreign
 * key behind it — so a plain entity delete leaves passages a shopper can still retrieve and a merchant
 * can no longer see. That is the failure spec R8 exists to prevent, arriving through the admin API
 * instead of through a re-upload.
 *
 * It owns policy — {@see ShopInfoUpload} holds the extension list and the size bound — and nothing
 * else.
 * Extraction, chunking and embedding live in {@see \Swag\AssistantStarterKit\ShopInfo\DocumentIngestion}.
 *
 * Not an `AbstractController`, following {@see AssistantTraceExportController}: it needs no container,
 * no twig and no `setContainer()` call.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class ShopInfoDocumentController
{
    public function __construct(
        private readonly DocumentIngestionFactory $ingestions,
        private readonly DocumentRecords $records,
        private readonly PassageLookup $lookup,
        private readonly SystemConfigAssistantConfig $config,
    ) {}

    #[Route(
        path: '/api/_action/swag-assistant/shop-info/upload',
        name: 'api.action.swag_assistant.shop_info.upload',
        defaults: ['_acl' => ['swag_assistant_document:create']],
        methods: ['POST'],
    )]
    public function upload(Request $request): JsonResponse
    {
        $upload = ShopInfoUpload::fromRequest($request);

        if ($upload->error !== null) {
            return self::refusal($upload->error);
        }

        try {
            $documentId = $this->ingestionFor($upload->salesChannelId)->ingest(
                $upload->name,
                $upload->bytes,
                $upload->salesChannelId,
            );
        } catch (\Throwable $failure) {
            return self::indexingFailed($failure);
        }

        return $this->indexed($documentId, $upload->salesChannelId);
    }

    /**
     * Re-index from the text already stored, so this needs no upload (spec R9).
     *
     * The action a merchant needs after changing the embedding model, which invalidates every vector
     * in the store — see `ShopInfoVectorTable` for why that refusal exists rather than a silent
     * mismatch.
     */
    #[Route(
        path: '/api/_action/swag-assistant/shop-info/reindex',
        name: 'api.action.swag_assistant.shop_info.reindex',
        defaults: ['_acl' => ['swag_assistant_document:update']],
        methods: ['POST'],
    )]
    public function reindex(Request $request): JsonResponse
    {
        $document = $this->requested($request);

        if ($document === null) {
            return self::refusal('No such document.', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->ingestionFor($document->salesChannelId)->reindex($document->id);
        } catch (\Throwable $failure) {
            return self::indexingFailed($failure);
        }

        return $this->indexed($document->id, $document->salesChannelId);
    }

    /**
     * The document and its passages, in that order of importance.
     *
     * Passages first: the reverse order can leave passages whose document is gone, and those are
     * retrievable by a shopper and invisible to the merchant.
     */
    #[Route(
        path: '/api/_action/swag-assistant/shop-info/delete',
        name: 'api.action.swag_assistant.shop_info.delete',
        defaults: ['_acl' => ['swag_assistant_document:delete']],
        methods: ['POST'],
    )]
    public function delete(Request $request): JsonResponse
    {
        $document = $this->requested($request);

        if ($document === null) {
            return self::refusal('No such document.', Response::HTTP_NOT_FOUND);
        }

        $this->lookup->deleteDocument($document->id);
        $this->records->delete($document->id);

        return new JsonResponse(['deleted' => $document->name]);
    }

    /**
     * What the document ended up as, read back rather than assumed.
     *
     * The status comes from the record and not from the fact that indexing returned, because those
     * are different claims: `DocumentIngestion` records a failure on the document *and* rethrows, so
     * the row is the authority on what happened.
     */
    private function indexed(string $documentId, string $salesChannelId): JsonResponse
    {
        $document = $this->records->findById($documentId);

        return new JsonResponse([
            'documentId' => $documentId,
            'status' => $document?->status,
            'chunkCount' => $document?->chunkCount ?? 0,
            'dimension' => $document?->dimension ?? 0,
            'salesChannelId' => $salesChannelId,
        ]);
    }

    private function requested(Request $request): ?\Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument
    {
        $documentId = (string) $request->request->get('documentId', '');

        return $documentId === '' ? null : $this->records->findById($documentId);
    }

    /**
     * An indexing failure is a merchant-facing fact, not a server fault.
     *
     * "This PDF is a scan and has no text layer" is the answer, and it is already recorded on the
     * document. Letting it become a 500 would make the Administration show a generic error beside a
     * row whose own status column holds the real reason.
     */
    private static function indexingFailed(\Throwable $failure): JsonResponse
    {
        return self::refusal($failure->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function ingestionFor(string $salesChannelId): \Swag\AssistantStarterKit\ShopInfo\DocumentIngestion
    {
        return $this->ingestions->forSalesChannel(
            $salesChannelId,
            $this->config->forSalesChannel($salesChannelId)->embeddingModel,
        );
    }

    private static function refusal(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['errors' => [['detail' => $message]]], $status);
    }
}
