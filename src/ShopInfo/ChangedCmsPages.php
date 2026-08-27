<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotDefinition;
use Shopware\Core\Content\Cms\CmsPageDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

/**
 * Which CMS pages a write touched.
 *
 * **A slot is what actually changes.** Editing the text of a legal page in the Administration writes
 * `cms_slot` and its translation; the `cms_page` row itself may not be touched at all. Listening only
 * for `cms_page` would produce a subscriber that fires on renaming a page and never on rewriting one —
 * which is exactly the wrong way round.
 *
 * Slot ids are resolved to their page with one query rather than through the DAL: this runs on the way
 * past every entity write in the shop, and loading entity graphs to answer "is this interesting?"
 * would make every save in the Administration pay for this feature.
 */
final readonly class ChangedCmsPages
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return list<string> page ids in hex, empty when the write touched no CMS content
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function from(EntityWrittenContainerEvent $event): array
    {
        $pageIds = self::hexIds($event, CmsPageDefinition::ENTITY_NAME);
        $slotIds = self::hexIds($event, CmsSlotDefinition::ENTITY_NAME);

        if ($slotIds !== []) {
            $pageIds = [...$pageIds, ...$this->pagesOfSlots(array_values(array_unique($slotIds)))];
        }

        return array_values(array_unique($pageIds));
    }

    /**
     * @param list<string> $slotIds
     *
     * @return list<string>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function pagesOfSlots(array $slotIds): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(section.cms_page_id))
             FROM cms_slot slot
             JOIN cms_block block ON block.id = slot.cms_block_id
             JOIN cms_section section ON section.id = block.cms_section_id
             WHERE slot.id IN (:ids)',
            ['ids' => array_map('hex2bin', $slotIds)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::BINARY],
        );

        return array_values(array_filter($rows, static fn(mixed $id): bool => \is_string($id)));
    }

    /**
     * @return list<string>
     */
    private static function hexIds(EntityWrittenContainerEvent $event, string $entity): array
    {
        $ids = [];

        foreach ($event->getPrimaryKeys($entity) as $id) {
            if (\is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
