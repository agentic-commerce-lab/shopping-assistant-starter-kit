<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ConversationEntity>
 */
class ConversationCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ConversationEntity::class;
    }
}
