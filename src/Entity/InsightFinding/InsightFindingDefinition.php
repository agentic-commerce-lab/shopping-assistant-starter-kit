<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightFinding;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationDefinition;
use Swag\AssistantStarterKit\Entity\InsightRun\InsightRunDefinition;

/**
 * `swag_assistant_insight_finding` — one thing the judge says went wrong in one conversation.
 *
 * **`conversationId` is deliberately not `Required`.** The pruner deletes findings with their
 * conversations, but a row that outlives its link through a delete order nobody anticipated must
 * still read as a finding. A dead link degrades into a finding without a quote to click; a missing
 * row is a silent loss, and the merchant never learns it was there.
 *
 * `type` is a plain string column holding a
 * {@see \Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFindingType} value. The enum is the
 * guard — nothing outside the closed set is ever written (D24) — and the column stays a string so
 * that widening the set later needs no migration.
 *
 * `quote` is nullable although the validator refuses a finding without one: a row whose quote was
 * cleared by a future privacy measure must still be readable, and `NOT NULL` would make that a
 * migration rather than an update.
 */
class InsightFindingDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_assistant_insight_finding';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return InsightFindingEntity::class;
    }

    public function getCollectionClass(): string
    {
        return InsightFindingCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('run_id', 'runId', InsightRunDefinition::class))->addFlags(new Required()),
            new FkField('conversation_id', 'conversationId', ConversationDefinition::class),
            (new StringField('type', 'type'))->addFlags(new Required()),
            new StringField('severity', 'severity'),
            (new LongTextField('summary', 'summary'))->addFlags(new Required()),
            new LongTextField('quote', 'quote'),
            new LongTextField('suggestion', 'suggestion'),
            new ManyToOneAssociationField('run', 'run_id', InsightRunDefinition::class),
            new ManyToOneAssociationField('conversation', 'conversation_id', ConversationDefinition::class),
        ]);
    }
}
