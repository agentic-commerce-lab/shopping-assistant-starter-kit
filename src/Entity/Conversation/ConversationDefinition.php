<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventDefinition;

/**
 * `swag_assistant_conversation` — the conversation memory and the trace's parent, in one table.
 *
 * **One table, two readers — do not build a second one** (`ARCHITECTURE.md`). The widget re-hydrates
 * from `transcript`; the merchant reads the associated trace events.
 *
 * A written `EntityDefinition` rather than a custom entity, and that is a correction rather than a
 * preference (ruling R78): `entities.xml` custom entities are registered exclusively by
 * `AppManager`, so they are an **App** feature. D1 chose a plugin, so this is the only path that
 * exists — and it restores the table names `ARCHITECTURE.md` documented from the start.
 *
 * **No field declared here carries `ApiAware`** — in particular not `transcript`, because a
 * conversation transcript reachable over the API would be a data-protection problem rather than a
 * feature. `createdAt` and `updatedAt` are the exception and not ours to choose: `EntityDefinition`
 * appends both automatically, `ApiAware` included (`EntityDefinition::defaultFields()`), so they are
 * neither declared above nor suppressible. Timestamps carry no conversation content.
 */
class ConversationDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_conversation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ConversationEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ConversationCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('sales_channel_id', 'salesChannelId'))->addFlags(new Required()),
            new StringField('locale', 'locale'),
            new IntField('turn_count', 'turnCount'),
            new StringField('outcome', 'outcome'),
            // Measured with a wall clock around the runner call, so it is honest. `first_token_ms`
            // has no equivalent here: it is a streaming metric and streaming is cut (R62).
            new IntField('total_ms', 'totalMs'),
            new JsonField('transcript', 'transcript'),
            new OneToManyAssociationField('events', TraceEventDefinition::class, 'conversation_id'),
        ]);
    }
}
