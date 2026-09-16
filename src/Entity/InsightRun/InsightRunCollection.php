<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightRun;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<InsightRunEntity>
 */
class InsightRunCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return InsightRunEntity::class;
    }
}
