<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightFinding;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<InsightFindingEntity>
 */
class InsightFindingCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return InsightFindingEntity::class;
    }
}
