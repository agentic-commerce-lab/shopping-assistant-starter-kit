<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Which of the shop's pages count as legal pages, and which ids they have per channel.
 *
 * Configuration only — no CMS query, no entity graph. Split from {@see CmsLegalPages} because the
 * change subscriber asks "is this page interesting?" on the way past every entity write in the shop,
 * and answering that by loading pages would make every save in the Administration pay for this
 * feature.
 */
final readonly class LegalPageConfig
{
    /**
     * The pages worth indexing, and the label each becomes.
     *
     * `contactPage` and `revocationRequestPage` are deliberately absent: a contact page is a form, and
     * a revocation *request* page is the form for exercising the right rather than the text describing
     * it. Indexing a form yields field labels, which retrieve as prose and answer nothing.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'imprintPage' => 'Imprint',
        'privacyPage' => 'Privacy policy',
        'revocationPage' => 'Right of withdrawal',
        'tosPage' => 'Terms and conditions',
        'shippingPaymentInfoPage' => 'Shipping and payment',
    ];

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    /**
     * Every configured legal page of one channel, keyed by page id.
     *
     * @return array<string, array{key: string, label: string}>
     */
    public function entries(string $salesChannelId): array
    {
        $entries = [];

        foreach (self::PAGES as $key => $label) {
            $pageId = $this->systemConfig->getString('core.basicInformation.' . $key, $salesChannelId);

            if ($pageId !== '') {
                $entries[$pageId] = ['key' => $key, 'label' => $label];
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function pageIds(string $salesChannelId): array
    {
        return array_keys($this->entries($salesChannelId));
    }
}
