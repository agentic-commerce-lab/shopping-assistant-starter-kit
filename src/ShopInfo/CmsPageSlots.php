<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\Content\Cms\CmsPageEntity;

/**
 * A CMS page entity flattened into the plain slot list {@see CmsPageText} works on.
 *
 * The seam between Shopware's entity graph and a pure function: this class knows about sections,
 * blocks and slots, and nothing downstream of it does. That is what lets the extraction rules — which
 * is where spec R1 said the failure modes are — be tested without a database.
 *
 * Typed against the DAL entities rather than duck-typed, matching how the rest of this plugin reads
 * entities: the associations are requested by the criteria that loaded the page, so `null` here means
 * a page with no content, not an unknown shape to defend against.
 */
final readonly class CmsPageSlots
{
    /**
     * Every slot of every block of every section, in page order.
     *
     * @return list<array{type: string, config: array<array-key, mixed>}>
     */
    public static function of(CmsPageEntity $page): array
    {
        $slots = [];

        foreach ($page->getSections() ?? [] as $section) {
            foreach ($section->getBlocks() ?? [] as $block) {
                foreach ($block->getSlots() ?? [] as $slot) {
                    $slots[] = ['type' => $slot->getType(), 'config' => $slot->getConfig() ?? []];
                }
            }
        }

        return $slots;
    }

    /** The page's own name, so a merchant recognises the row as the page they edit. */
    public static function nameOf(CmsPageEntity $page): string
    {
        return $page->getName() ?? 'page';
    }
}
