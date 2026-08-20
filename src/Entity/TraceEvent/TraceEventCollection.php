<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\TraceEvent;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<TraceEventEntity>
 */
class TraceEventCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TraceEventEntity::class;
    }
}
