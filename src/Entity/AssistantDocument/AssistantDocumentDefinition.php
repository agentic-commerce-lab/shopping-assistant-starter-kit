<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\AssistantDocument;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * `swag_assistant_document` — one uploaded shop document (spec R9).
 *
 * `createdAt` and `updatedAt` are not declared here: `EntityDefinition::defaultFields()` appends both
 * to every definition, so the table must carry both columns. `dal:validate` is what says so, and it
 * is worth running after any schema change for exactly that reason.
 *
 * **`sales_channel_id` is a plain `StringField`, not an `FkField`.** A foreign key would cascade a
 * channel deletion into the document list, which is arguably right — but it would also make this
 * table depend on Shopware's sales-channel table in a plugin that otherwise depends on nothing, and
 * the passages in the vector store carry the same id with no constraint at all. One of the two would
 * enforce it and the other would not, and the half-enforced version is the one that surprises people.
 *
 * The extracted `text` is stored so re-indexing needs no access to the original file, which is also
 * why the plugin never has to decide where uploaded files live.
 */
class AssistantDocumentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_document';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AssistantDocumentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AssistantDocumentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required()),
            new StringField('extension', 'fileExtension'),
            (new StringField('sales_channel_id', 'salesChannelId'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new LongTextField('status_reason', 'statusReason'),
            new IntField('chunk_count', 'chunkCount'),
            new IntField('dimension', 'dimension'),
            new LongTextField('text', 'text'),
        ]);
    }
}
