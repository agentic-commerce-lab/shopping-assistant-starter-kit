<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\AssistantDocument;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AssistantDocumentEntity>
 */
class AssistantDocumentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AssistantDocumentEntity::class;
    }
}
