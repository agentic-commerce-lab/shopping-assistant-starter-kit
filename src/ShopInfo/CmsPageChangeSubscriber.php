<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a re-index when a merchant edits one of the shop's own legal pages.
 *
 * **This runs on the way past every entity write in the shop**, so the cheapest question is asked
 * first: did this write touch CMS content at all. For the overwhelming majority of writes the answer
 * is no and nothing else happens — no channels loaded, no configuration read.
 *
 * Nothing is indexed here. A {@see Message\ReindexShopPage} is dispatched per affected channel and
 * page, and {@see ReindexShopPageHandler} does the work off the request, because embedding is a paid
 * network call and a provider timeout must not surface as a failure to save content the merchant did
 * save.
 *
 * The toggle is checked here (via {@see AutoIndexPolicy}) *and* again in the handler. Not redundancy:
 * here it avoids queueing work nobody asked for, there it avoids doing work the merchant switched off
 * while it sat in the queue.
 */
final readonly class CmsPageChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChangedCmsPages $changed,
        private AutoIndexPolicy $policy,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [EntityWrittenContainerEvent::class => 'onWritten'];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function onWritten(EntityWrittenContainerEvent $event): void
    {
        foreach ($this->policy->messagesFor($this->changed->from($event)) as $message) {
            $this->queue($message);
        }
    }

    private function queue(Message\ReindexShopPage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $failure) {
            // **The merchant's save must not fail because our queueing did.** They edited a page;
            // whether the assistant re-reads it is our problem, and a broken transport would otherwise
            // surface as an inability to save content that was in fact saved. The "Index shop pages"
            // button is the recourse, and it reports failures where they are visible.
            $this->logger->warning('Could not queue a shop page re-index.', [
                'salesChannelId' => $message->salesChannelId,
                'cmsPageId' => $message->cmsPageId,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
