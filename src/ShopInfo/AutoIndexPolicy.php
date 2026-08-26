<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\ShopInfo\Message\ReindexShopPage;

/**
 * Which changed pages are worth re-indexing, and for which sales channels.
 *
 * Split from {@see CmsPageChangeSubscriber} so the decision is testable without an entity-written
 * event, and because the subscriber's job is to notice a change rather than to have an opinion about
 * it.
 *
 * **The order of the checks is the design**, because this is asked on the way past writes in a live
 * shop. The toggle is read before the page list, and the page list is configuration only — no CMS
 * query — so a shop with the feature off pays a handful of config reads and nothing more.
 */
final readonly class AutoIndexPolicy
{
    public function __construct(
        private LegalPageConfig $legal,
        private SystemConfigAssistantConfig $config,
        private EntityRepository $salesChannels,
    ) {}

    /**
     * @param list<string> $changedPageIds
     *
     * @return list<ReindexShopPage>
     */
    public function messagesFor(array $changedPageIds): array
    {
        if ($changedPageIds === []) {
            return [];
        }

        $messages = [];

        foreach ($this->activeSalesChannelIds() as $salesChannelId) {
            $config = $this->config->forSalesChannel($salesChannelId);

            // Both conditions, because either alone would spend money the merchant did not ask for:
            // the toggle is the request, and the model is what makes indexing possible at all (R13).
            if (!$config->autoIndexShopPages || $config->embeddingModel === '') {
                continue;
            }

            foreach (array_intersect($changedPageIds, $this->legal->pageIds($salesChannelId)) as $pageId) {
                $messages[] = new ReindexShopPage($salesChannelId, $pageId);
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function activeSalesChannelIds(): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        $ids = [];

        foreach ($this->salesChannels->searchIds($criteria, Context::createDefaultContext())->getIds() as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
