<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The shop's own legal pages, as HTML, for one sales channel.
 *
 * These are the documents a shopper actually asks about and the ones a merchant should never have had
 * to upload: the shop already has them, configured under `core.basicInformation`, and the spec's
 * *verified* table recorded that those keys hold real CMS page ids.
 *
 * **Read in the sales channel's own language.** A page has one row per language, and reading it in the
 * system language would index the English terms for a German storefront — passages that retrieve
 * perfectly and answer in the wrong language. The channel's `languageId` leads the context's language
 * chain, with the system language behind it so a page translated only once still resolves.
 *
 * Outside `Core\` because it names Shopware types, which `Core` may not.
 */
final readonly class CmsLegalPages
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
        private EntityRepository $cmsPages,
        private SalesChannelLanguage $language,
        private SystemConfigService $systemConfig,
    ) {}

    /**
     * @return list<array{key: string, label: string, name: string, html: string}>
     */
    public function forSalesChannel(string $salesChannelId): array
    {
        $context = $this->language->contextFor($salesChannelId);
        $wanted = [];

        foreach (self::PAGES as $key => $label) {
            $pageId = $this->systemConfig->getString('core.basicInformation.' . $key, $salesChannelId);

            if ($pageId !== '') {
                $wanted[$pageId] = ['key' => $key, 'label' => $label];
            }
        }

        if ($wanted === []) {
            return [];
        }

        return $this->read(array_keys($wanted), $wanted, $context);
    }

    /**
     * @param list<string>                                     $pageIds
     * @param array<string, array{key: string, label: string}> $wanted
     *
     * @return list<array{key: string, label: string, name: string, html: string}>
     */
    private function read(array $pageIds, array $wanted, Context $context): array
    {
        $criteria = new Criteria($pageIds);
        $criteria->addAssociation('sections.blocks.slots');

        $pages = [];

        foreach ($this->cmsPages->search($criteria, $context) as $pageId => $page) {
            $meta = $wanted[$pageId] ?? null;

            if ($meta === null || !$page instanceof CmsPageEntity) {
                continue;
            }

            $pages[] = [
                'key' => $meta['key'],
                'label' => $meta['label'],
                'name' => self::nameFor($meta['label'], CmsPageSlots::nameOf($page)),
                'html' => CmsPageText::fromSlots(CmsPageSlots::of($page)),
            ];
        }

        return $pages;
    }

    /**
     * What the document is called: the role, plus the page's own name when that adds anything.
     *
     * A merchant needs to recognise the row as the page they edit, so the page name is worth showing —
     * but "Imprint (Imprint)" is what naive concatenation produces on a shop where the two coincide,
     * and it reads like a bug. Appended only when it differs.
     */
    private static function nameFor(string $label, string $pageName): string
    {
        if ($pageName === 'page' || mb_strtolower($pageName) === mb_strtolower($label)) {
            return $label;
        }

        return \sprintf('%s (%s)', $label, $pageName);
    }
}
