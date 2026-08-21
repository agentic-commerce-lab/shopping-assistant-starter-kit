<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\TraceEvent;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationDefinition;

/**
 * `swag_assistant_trace_event` — one pipeline stage of one turn.
 *
 * `createdAt` and `updatedAt` are not declared: `EntityDefinition::defaultFields()` appends both to
 * every definition, so the table must carry both columns even though a trace event is written once
 * and never updated. `dal:validate` is what says so, and it is worth running after any schema change
 * for exactly this reason.
 *
 * `seq` is stored rather than derived from insertion order: the four payload fields that
 * `ARCHITECTURE.md` calls "the four ways this class of product lies" are only interpretable in
 * order, and a stage that ran twice must remain visible as two rows (ruling R18 — `stages()`
 * de-duplicates, `events()` does not, and the tool-call budget failures live in the second round).
 */
class TraceEventDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_trace_event';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TraceEventEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TraceEventCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('conversation_id', 'conversationId', ConversationDefinition::class))->addFlags(new Required()),
            new IntField('seq', 'seq'),
            (new StringField('stage', 'stage'))->addFlags(new Required()),
            new JsonField('payload', 'payload'),
            new IntField('elapsed_ms', 'elapsedMs'),
            new ManyToOneAssociationField('conversation', 'conversation_id', ConversationDefinition::class),
        ]);
    }
}
