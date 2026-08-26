<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * A read context whose language chain leads with one sales channel's own language.
 *
 * **Why this is not `Context::createDefaultContext()`.** That reads the system language, so a German
 * storefront's revocation page would be indexed in English — passages that retrieve perfectly and
 * answer in the wrong language, which no test and no status column would show. The channel's language
 * leads, with the system language behind it so a page translated only once still resolves.
 *
 * `SystemSource` because this runs from an admin request or the console rather than as a shopper, and
 * CMS pages are not permission-scoped.
 *
 * Its own class because language resolution is a separate concern from finding the pages, and because
 * Mago's complexity budget is per class.
 */
final readonly class SalesChannelLanguage
{
    public function __construct(
        private EntityRepository $salesChannels,
    ) {}

    public function contextFor(string $salesChannelId): Context
    {
        $channel = $this->salesChannels
            ->search(new Criteria([$salesChannelId]), Context::createDefaultContext())
            ->first();

        $languageId = $channel instanceof SalesChannelEntity ? $channel->getLanguageId() : null;

        $chain =
            $languageId !== null && $languageId !== Defaults::LANGUAGE_SYSTEM
                ? [$languageId, Defaults::LANGUAGE_SYSTEM]
                : [Defaults::LANGUAGE_SYSTEM];

        return new Context(new SystemSource(), [], Defaults::CURRENCY, $chain);
    }
}
