<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Psr\Log\LoggerInterface;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\ShopInfo\Message\ReindexShopPage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Indexes one changed shop page, off the request that changed it.
 *
 * **The settings are re-read here, not trusted from the message.** A message can sit in the queue while
 * a merchant switches the toggle off or clears the embedding model, and acting on the state at
 * dispatch time would spend money after they said to stop.
 *
 * A failure is logged and swallowed rather than rethrown. That is the opposite of this codebase's
 * usual instinct and it is deliberate: the work is a best-effort refresh of a document that still
 * exists and still answers from its previous wording, and a retry storm against a paid provider is
 * worse than a stale passage. The merchant's recourse is the "Index shop pages" button, which reports
 * failures where they can see them.
 */
#[AsMessageHandler(handles: ReindexShopPage::class)]
final readonly class ReindexShopPageHandler
{
    public function __construct(
        private ShopPageIndexer $pages,
        private SystemConfigAssistantConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ReindexShopPage $message): void
    {
        $config = $this->config->forSalesChannel($message->salesChannelId);

        if (!$config->autoIndexShopPages || $config->embeddingModel === '') {
            return;
        }

        try {
            $this->pages->indexPage($message->salesChannelId, $message->cmsPageId, $config->embeddingModel);
        } catch (\Throwable $failure) {
            $this->logger->warning('Automatic shop page indexing failed.', [
                'salesChannelId' => $message->salesChannelId,
                'cmsPageId' => $message->cmsPageId,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
